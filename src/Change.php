<?php
namespace OliverHader\Rebase;

class Change {
	public $project;
	public $branch;
	public $topic;
	public $changeId;
	public $gerritId;
	public $subject;
	/** @var PatchSet */
	public $currentPatchSet;
}
