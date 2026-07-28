<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/SearchQuery.php';
require_once __DIR__ . '/TeamCalDatabase.php';
require_once __DIR__ . '/TeamCal.php';
require_once __DIR__ . '/IcsParser.php';
require_once __DIR__ . '/NotesDatabase.php';
require_once __DIR__ . '/Notes.php';
require_once __DIR__ . '/TodoDatabase.php';
require_once __DIR__ . '/Todo.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/helpers.php';

Auth::startSession();
Database::connection();
