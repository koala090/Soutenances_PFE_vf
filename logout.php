<?php
session_start();
session_destroy();
header('Location: login.php?message=deconnexion_reussie');
exit;
?>