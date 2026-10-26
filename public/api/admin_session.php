<?php
require __DIR__ . '/../../src/bootstrap.php';
Http::run(fn() => Auth::status());
