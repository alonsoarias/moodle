<?php
require_once(__DIR__ . '/../../../config.php');
redirect(new moodle_url('/admin/tool/bruteforce/manage.php', ['section' => 'lists']));

