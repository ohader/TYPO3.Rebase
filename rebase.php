<?php
require_once __DIR__ . '/vendor/autoload.php';
\OliverHader\Rebase\Controller::create(
	'master',
	'origin/master',
	'b362e3667087e37e8730ae6ffb7c47bc6fa73f87'
)->run();