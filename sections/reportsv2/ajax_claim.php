<?php

if (!$Viewer->permitted('admin_reports')) {
    error(403);
}

echo (new Gazelle\Manager\Torrent\Report(new Gazelle\Manager\Torrent))
    ->findById((int)($_GET['id'] ?? 0))
    ?->claim($Viewer->id()) ?? 0;
