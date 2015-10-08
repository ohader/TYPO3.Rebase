<?php
namespace OliverHader\Rebase;

use OliverHader\Rebase\Exceptions;

class Controller {

#	const QUERY = 'ssh review.typo3.org "gerrit query" %s status:%s project:%s branch:%s change:39901';
#	const QUERY = 'ssh review.typo3.org "gerrit query" %s status:%s project:%s branch:%s change:43915';
	const QUERY = 'ssh review.typo3.org "gerrit query" %s status:%s project:%s branch:%s owner:oliver@typo3.org';
	const QUERY_STATUS = 'open';
	const QUERY_PROJECT = 'Packages/TYPO3.CMS';
	const QUERY_OPTIONS = '--format JSON --current-patch-set --all-approvals';

	const FETCH = 'git fetch --quiet %s%s %s && git log -1 --pretty=format:%%H FETCH_HEAD^';
	const FETCH_REPOSITORY = 'git://git.typo3.org/';
	const SHOW_NAMES = 'git show --name-status --pretty="format:" %s';
	const STATUS = 'git status -s';

	const LOG = 'git log --pretty=format:%%H %s^';
	const LASTCOMMIT = 'git log -1 --pretty=format:%H';
	const CHERRYPICK = 'git cherry-pick -X %s %s';
	const COMMIT = 'git commit --no-edit -q';
	const COMMIT_AMEND = 'git commit -a --amend --no-edit -q';
	const REMOVE = 'git rm %s';
	const RESET = 'git reset --quiet --hard %s && git clean --quiet -df';
	const PUSH = 'git push origin HEAD:refs/for/%s%s';

	const APPROVE = 'ssh review.typo3.org "gerrit review" "--message \'%s\'" --project %s --verified %s --code-review %s %s';
	const APPROVE_MESSAGE = ' Automated rebasing to %s - Please consider updating your reviews';
	const APPROVE_SUMMARY = ' V:%s, R:%s as a summary of previous scores (limited to [-1;+1])';

	/**
	 * @var \Symfony\Component\Console\Input\InputInterface
	 */
	protected $consoleInput;

	/**
	 * @var \Symfony\Component\Console\Output\OutputInterface
	 */
	protected $consoleOutput;

	/**
	 * @var string
	 */
	protected $branch;

	/**
	 * @var string
	 */
	protected $commit;

	/**
	 * @var string
	 */
	protected $head;

	/** @var Change[] */
	protected $changes = array();

	/**
	 * @var array
	 */
	protected $log = array();

	/**
	 * @var boolean
	 */
	protected $newLineIsMissing = FALSE;

	/**
	 * @param string $branch
	 * @param string $head
	 * @param string $commit
	 * @return Controller
	 */
	static public function create($branch, $head, $commit) {
		return new Controller($branch, $head, $commit);
	}

	/**
	 * @param string $branch
	 * @param string $head
	 * @param string $commit
	 */
	public function __construct($branch, $head, $commit) {
		$this->consoleInput = new \Symfony\Component\Console\Input\ArrayInput(array());
		$this->consoleOutput = new \Symfony\Component\Console\Output\NullOutput();

		$this->branch = (string)$branch;
		$this->head = (string)$head;
		$this->commit = (string)$commit;

		if (is_resource(STDERR)) {
			fclose(STDERR);
		}
	}

	public function run() {
		try {
			$this->fetchLog();
			$this->receiveChanges();
			$this->processChanges();
		} catch (\Exception $exception) {
			$this->show('');
			$this->show('FAILURE: ' . $exception->getMessage());
			$this->show('');
		}

		$this->resetRepositoryToHead();
		$this->show('Finished');
	}

	protected function processChanges() {
		$this->show('Processing changes...');

		foreach ($this->changes as $change) {
			$this->fetchPatchSet($change->currentPatchSet);
			if ($this->isInLog($change->currentPatchSet->parent)) {
				try {
					$this->show('Rebasing change ' . $change->currentPatchSet->ref . '... ', FALSE);
					try {
						$this->resetRepositoryToHead();
						$change->currentPatchSet->lastCommit = $this->cherryPick('FETCH_HEAD');
						$this->show('is fine, skipping', TRUE, FALSE);
						continue;
					} catch (Exceptions\CherryPickException $exception) {
						$this->resetRepositoryToPreviousCommit();
						$change->currentPatchSet->lastCommit = $this->cherryPick('FETCH_HEAD');
					}
					$changeFiles = $this->showNames();
					if ($changeFiles->updatable()) {
						$this->show('CS... ', FALSE, FALSE);
						$this->applyCodeSniffer($changeFiles->updatable());
						$change->currentPatchSet->lastCommit = $this->commitAmend();
					}
					$this->resetRepositoryToHead();
					try {
						$this->cherryPick($change->currentPatchSet->lastCommit, 'theirs');
					} catch (Exceptions\CherryPickException $exception) {
						$statusFiles = $this->status();
						$differentFiles = array_diff($statusFiles->unresolved, $statusFiles->delete);
						if (!empty($differentFiles)) {
							throw $exception;
						}
						$this->remove($statusFiles->delete);
						$this->commit();
					}
					die();
					$this->pushChange($change);
					#$this->setApprovals($change->currentPatchSet);
					$this->show('done', TRUE, FALSE);
				} catch (Exceptions\EmptyCommitException $exception) {
					$this->show('! Problems on resolving commit content ' . $change->currentPatchSet->ref);
				} catch (Exceptions\CherryPickException $exception) {
					$this->show('! Problems on cherry-picking ' . $change->currentPatchSet->ref);
				} catch (Exceptions\PushException $exception) {
					$this->show('! Problems on pushing ' . $change->currentPatchSet->ref);
				} catch (Exceptions\ApproveException $exception) {
					$this->show('! Problems on approving ' . $change->currentPatchSet->ref);
				}
			} else {
				$this->show('.', FALSE, FALSE);
			}
		}
	}

	protected function setApprovals(PatchSet $patchSet) {
		throw new \RuntimeException();

		/** @var $ratings Rating[] */
		$ratings = array();
		$review = 0;
		$verify = 0;
		$comments = '';

		foreach ($patchSet->approvals as $approval) {
			$key = sha1($approval->email);

			if (isset($ratings[$key])) {
				$rating = $ratings[$key];
			} else {
				$rating = new Rating();
				$rating->email = $approval->email;
				$rating->name = $approval->name;
				$ratings[$key] = $rating;
			}

			switch ($approval->type) {
				case Approval::TYPE_Review:;
					$rating->review = $approval->value;
					$review = $this->getApprovalValue($review, $approval->value);
					break;
				case Approval::TYPE_Verify:;
					$rating->verify = $approval->value;
					$verify = $this->getApprovalValue($verify, $approval->value);
					break;
			}
		}

		foreach ($ratings as $rating) {
			$comments .= ' ' . $rating->__toString() . PHP_EOL;
		}

		$message = sprintf(self::APPROVE_MESSAGE, $this->branch) . PHP_EOL . PHP_EOL . sprintf(self::APPROVE_SUMMARY, $verify, $review);
		if ($comments) {
			$message .= PHP_EOL . PHP_EOL . $comments;
		}

		try {
			$this->executeCommand(
				$this->getApproveCommand($patchSet, $message, $review, $verify),
				TRUE
			);
		} catch (\RuntimeException $exception) {
			throw new Exceptions\ApproveException($exception->getMessage());
		}
	}

	/**
	 * @param integer $currentValue
	 * @param integer $newValue
	 * @param boolean $limitValue
	 * @return integer
	 */
	protected function getApprovalValue($currentValue, $newValue, $limitValue = TRUE) {
		if ($currentValue === 0 || $newValue < 0 && $newValue < $currentValue || $currentValue > 0 && $newValue > $currentValue) {
			$currentValue = intval($newValue);
		}

		// Limit value to the range of [-1;+1]
		if ($limitValue) {
			if ($currentValue > 1) {
				$currentValue = 1;
			} elseif ($currentValue < -1) {
				$currentValue = -1;
			}
		}

		return $currentValue;
	}

	protected function getApproveCommand(PatchSet $patchSet, $message, $review, $verify) {
		throw new \RuntimeException();

		$command = sprintf(
			self::APPROVE,
			$message,
			$patchSet->change->project,
			$verify,
			$review,
			$patchSet->lastCommit
		);
		return $command;
	}

	/**
	 * @param string $revision
	 * @param string $strategy
	 * @return string Last commit
	 */
	protected function cherryPick($revision = 'FETCH_HEAD', $strategy = 'resolve') {
		try {
			$this->executeCommand(
				$this->getCherryPickCommand($revision, $strategy),
				TRUE
			);
		} catch (\RuntimeException $exception) {
			throw new Exceptions\CherryPickException($exception->getMessage());
		}

		return $this->fetchLastCommit();
	}

	/**
	 * @param string $revision
	 * @param string $strategy
	 * @return string
	 */
	protected function getCherryPickCommand($revision, $strategy) {
		$command = sprintf(
			self::CHERRYPICK,
			$strategy,
			$revision
		);
		return $command;
	}

	protected function applyCodeSniffer(array $fileNames) {
		if (empty($fileNames)) {
			return;
		}

		$finder = \Symfony\CS\Finder\DefaultFinder::create();
		$finder->append($fileNames);

		$configuration = \Symfony\CS\Config\Config::create()
			->level(\Symfony\CS\FixerInterface::PSR2_LEVEL)
			->fixers([
				'remove_leading_slash_use',
				'single_array_no_trailing_comma',
				'spaces_before_semicolon',
				'unused_use',
				'concat_with_spaces',
				'whitespacy_lines'
			])
			->finder($finder);

		$command = new \Symfony\CS\Console\Command\FixCommand(null, $configuration);
		$command->run($this->consoleInput, $this->consoleOutput);
	}

	protected function pushChange(Change $change) {
		try {
			$this->executeCommand(
				$this->getPushCommand($change),
				TRUE
			);
		} catch (\RuntimeException $exception) {
			throw new Exceptions\PushException($exception->getMessage());
		}
	}

	protected function resetRepositoryToHead() {
		$this->executeCommand(
			$this->getResetRepositoryCommand($this->head)
		);
	}

	protected function resetRepositoryToPreviousCommit() {
		$this->executeCommand(
			$this->getResetRepositoryCommand($this->commit . '^')
		);
	}

	protected function getResetRepositoryCommand($revision) {
		$command = sprintf(
			self::RESET,
			$revision
		);
		return $command;
	}

	protected function fetchPatchSet(PatchSet $patchSet) {
		$response = $this->executeCommand(
			$this->getFetchCommand(
				$patchSet->change->project,
				$patchSet->ref
			)
		);

		if (!isset($response[0]) || strlen($response[0]) !== 40) {
			throw new \RuntimeException('Invalid parent commit on ref ' . $patchSet->ref);
		}

		$patchSet->parent = $response[0];
	}

	protected function getFetchCommand($project, $ref) {
		$command = sprintf(
			self::FETCH,
			self::FETCH_REPOSITORY,
			$project,
			$ref
		);
		return $command;
	}

	/**
	 * @param string $revision
	 * @return Files
	 */
	protected function showNames($revision = 'FETCH_HEAD') {
		$response = $this->executeCommand(
			$this->getShowNamesCommand($revision)
		);

		if (empty($response)) {
			throw new Exceptions\EmptyCommitException();
		}

		return $this->parseFiles($response);
	}

	protected function getShowNamesCommand($revision) {
		$command = sprintf(
			self::SHOW_NAMES,
			$revision
		);
		return $command;
	}

	protected function status() {
		$response = $this->executeCommand(self::STATUS);
		return $this->parseFiles($response);
	}

	protected function commit() {
		$this->executeCommand(self::COMMIT, TRUE);
		return $this->fetchLastCommit();
	}

	protected function commitAmend() {
		$this->executeCommand(self::COMMIT_AMEND, TRUE);
		return $this->fetchLastCommit();
	}

	/**
	 * @param string[]|string $fileNameOrNames
	 * @return array|null
	 */
	protected function remove($fileNameOrNames) {
		if (is_array($fileNameOrNames)) {
			$responses = array();
			foreach ($fileNameOrNames as $fileName) {
				$responses[] = $this->remove($fileName);
			}
			return $responses;
		}

		return $this->executeCommand(
			$this->getRemoveCommand($fileNameOrNames)
		);
	}

	protected function getRemoveCommand($fileName) {
		$command = sprintf(
			self::REMOVE,
			$fileName
		);
		return $command;
	}

	protected function receiveChanges() {
		$this->show('Fetching changes data... ', FALSE);

		$json = $this->executeCommand(
			$this->getQueryCommand()
		);

		$this->reconstitute($json);
		$this->show('[' . count($this->changes) . ']', TRUE, FALSE);
	}

	/**
	 * @param string[] $response
	 * @return Files
	 */
	protected function parseFiles(array $response) {
		$files = new Files();
		foreach ($response as $line) {
			if (preg_match('/^\s*(\w+)\s+(.+)$/', $line, $matches)) {
				$files->add($matches[1], $matches[2]);
			}
		}
		return $files;
	}

	protected function reconstitute(array $json) {
		foreach ($json as $element) {
			$changeElement = json_decode($element);

			if (is_object($changeElement) && isset($changeElement->project) && isset($changeElement->branch) && isset($changeElement->currentPatchSet)) {
				$change = new Change();
				$change->project = $changeElement->project;
				$change->branch = $changeElement->branch;

				if (isset($changeElement->topic)) {
					$change->topic = $changeElement->topic;
				} elseif (NULL !== $forgeId = $this->getFirstForgeId($changeElement)) {
					$change->topic = $forgeId;
				}

				$change->changeId = $changeElement->id;
				$change->gerritId = $changeElement->number;
				$change->subject = $changeElement->subject;

				$change->currentPatchSet = new PatchSet();
				$change->currentPatchSet->change = $change;
				$change->currentPatchSet->number = $changeElement->currentPatchSet->number;
				$change->currentPatchSet->revision = $changeElement->currentPatchSet->revision;
				$change->currentPatchSet->ref = $changeElement->currentPatchSet->ref;

				if (isset($changeElement->currentPatchSet->approvals)) {
					foreach ($changeElement->currentPatchSet->approvals as $approvalElement) {
						$approval = new Approval();
						$approval->patchSet = $change->currentPatchSet;
						$approval->type = $approvalElement->type;
						$approval->value = $approvalElement->value;

						$approval->name = $approvalElement->by->name;

						if (isset($approvalElement->by->email)) {
							$approval->email = $approvalElement->by->email;
						} else {
							$approval->email = sha1($approvalElement->by->name);
						}

						$change->currentPatchSet->approvals[] = $approval;
					}
				}

				$this->changes[] = $change;
			}
		}
	}

	protected function getFirstForgeId(\stdClass $changeElement) {
		$forgeId = NULL;

		if (isset($changeElement->trackingIds) && count($changeElement->trackingIds) === 1) {
			$trackingId = $changeElement->trackingIds[0];
			if ($trackingId->system === 'Forge' && preg_match('#^\d+$#', $trackingId->id)) {
				$forgeId = $trackingId->id;
			}
		}

		return $forgeId;
	}

	protected function getPushCommand(Change $change) {
		$command = sprintf(
			self::PUSH,
			$this->branch,
			($change->topic ? '/' . $change->topic : '')
		);
		return $command;
	}

	/**
	 * @return string
	 */
	protected function getQueryCommand() {
		$command = sprintf(
			self::QUERY,
			self::QUERY_OPTIONS,
			self::QUERY_STATUS,
			self::QUERY_PROJECT,
			$this->branch
		);
		return $command;
	}

	protected function getLogCommand() {
		$command = sprintf(
			self::LOG,
			$this->commit
		);
		return $command;
	}

	protected function fetchLastCommit() {
		$response = $this->executeCommand(
			self::LASTCOMMIT
		);

		if (!isset($response[0]) || strlen($response[0]) !== 40) {
			throw new \RuntimeException('Last commit could not be fetched');
		}

		return $response[0];
	}

	protected function fetchLog() {
		$this->show('Fetching Git log data...');

		$log = $this->executeCommand(
			$this->getLogCommand()
		);

		if (is_array($log) === FALSE || count($log) === 0) {
			throw new \RuntimeException('Log could not be fetched for commit ' . $this->commit);
		}

		$this->log = $log;
	}

	/**
	 * @param string $commit
	 * @return boolean
	 */
	protected function isInLog($commit) {
		return in_array($commit, $this->log, TRUE);
	}

	/**
	 * @param string $command
	 * @param bool $failOnError
	 * @return array|null
	 */
	protected function executeCommand($command, $failOnError = FALSE) {
		$return = NULL;
		$response = NULL;

		ob_start();
		exec($command, $response, $return);
		ob_end_clean();

		if ($failOnError === TRUE && (is_null($return) || $return !== 0)) {
			throw new \RuntimeException('Execution of command "' . $command . '" failed');
		}

		return $response;
	}

	protected function show($message, $newLine = TRUE, $prependMissingNewLine = TRUE) {
		if ($this->newLineIsMissing && $prependMissingNewLine) {
			echo PHP_EOL;
		}

		echo $message;

		if ($newLine) {
			echo PHP_EOL;
			$this->newLineIsMissing = FALSE;
		} else {
			$this->newLineIsMissing = TRUE;
		}
	}
}
