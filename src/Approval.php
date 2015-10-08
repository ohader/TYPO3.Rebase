<?php
namespace OliverHader\Rebase;

class Approval {
	const TYPE_Verify = 'VRIF';
	const TYPE_Review = 'CRVW';

	/** @var PatchSet */
	public $patchSet;
	public $type;
	public $value;
	public $name;
	public $email;
}