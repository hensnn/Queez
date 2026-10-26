<?php
require __DIR__ . '/../../src/bootstrap.php';
Http::run(function () {
    Auth::require();
    Auth::logout();
});
