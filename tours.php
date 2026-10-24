<?php
session_start();
header('Location: index.php?notice=tours_unavailable', true, 302);
exit();
