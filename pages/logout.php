<?php
session_start();
session_unset();
session_destroy();

header("Location: /alke/index.php");
exit();
