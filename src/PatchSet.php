<?php
namespace OliverHader\Rebase;

class PatchSet {
	/** @var Change */
	public $change;
	public $number;
	public $revision;
	public $lastCommit;
	public $ref;
	public $parent;
	/** @var Approval[] */
	public $approvals = array();
}
