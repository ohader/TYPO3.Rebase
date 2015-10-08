<?php
namespace OliverHader\Rebase;

class Rating {
	public $name;
	public $email;
	public $verify = 0;
	public $review = 0;
	public function __toString() {
		return sprintf(
			'V:%s, R:%s from %s <%s>',
			$this->verify, $this->review,
			$this->name, $this->email
		);
	}
}