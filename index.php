<?php
require_once 'includes/functions.php';
verifier_connexion();

$user = get_user_info();

// Redirection automatique vers le dashboard approprié - CORRECTION ICI
switch ($user['role']) {
    case 'etudiant':
        header('Location: /Soutenances_PFE/dashboards/etudiant.php');
        break;
    case 'professeur':
        header('Location: /Soutenances_PFE/dashboards/professeur.php');
        break;
    case 'coordinateur':
        header('Location: /Soutenances_PFE/dashboards/coordinateur.php');
        break;
    case 'directeur':
        header('Location: /Soutenances_PFE/dashboards/directeur.php');
        break;
    case 'assistante':
        header('Location: /Soutenances_PFE/dashboards/assistante.php');
        break;
    default:
        header('Location: /Soutenances_PFE/login.php');
}
exit;
?>