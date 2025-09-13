<?php
require __DIR__.'/db.php';
session_destroy();
header('Location: '.BASE_URL);