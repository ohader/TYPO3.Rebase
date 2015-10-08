<?php
namespace OliverHader\Rebase;

class Files {

	/**
	 * @var string[
	 */
	public $map = array(
		'U' => 'unresolved',
		'D' => 'delete',
		'M' => 'modify',
		'A' => 'add',
	);

	/**
	 * @var string[]
	 */
	public $unresolved = array();

	/**
	 * @var string[]
	 */
	public $delete = array();

	/**
	 * @var string[]
	 */
	public $modify = array();

	/**
	 * @var string[]
	 */
	public $add = array();

	/**
	 * @param string $chars
	 * @param string $fileName
	 */
	public function add($chars, $fileName) {
		foreach (str_split($chars) as $char) {
			if (!isset($this->map[$char])) {
				throw new \RuntimeException('Unknown character identifier "' . $char . '"');
			}

			$this->{$this->map[$char]}[] = $fileName;
		}
	}

	/**
	 * @return string[]
	 */
	public function updatable() {
		return array_merge(
			$this->modify,
			$this->add
		);
	}

}
