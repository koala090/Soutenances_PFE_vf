<?php
function demarrer_session() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// Vérifie si l'utilisateur est connecté
function verifier_connexion() {
    demarrer_session();
    if (!isset($_SESSION['user_id'])) {
        header('Location: /projet-soutenances/login.php');
        exit;
    }
}

// Vérifie si l'utilisateur a un rôle autorisé
function verifier_role($roles_autorises) {
    verifier_connexion();
    
    if (!is_array($roles_autorises)) {
        $roles_autorises = [$roles_autorises];
    }
    
    if (!in_array($_SESSION['user_role'], $roles_autorises)) {
        header('Location: /projet-soutenances/index.php?error=acces_refuse');
        exit;
    }
}

// Récupère les informations de l'utilisateur connecté
function get_user_info() {
    demarrer_session();
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'nom' => $_SESSION['user_nom'] ?? '',
        'prenom' => $_SESSION['user_prenom'] ?? '',
        'email' => $_SESSION['user_email'] ?? '',
        'role' => $_SESSION['user_role'] ?? '',
        'filiere_id' => $_SESSION['user_filiere'] ?? null
    ];
}

// Génère un message d'alerte Bootstrap
function afficher_message($type, $message) {
    $classes = [
        'success' => 'alert-success',
        'error' => 'alert-danger',
        'warning' => 'alert-warning',
        'info' => 'alert-info'
    ];
    
    $classe = $classes[$type] ?? 'alert-info';
    return "<div class='alert {$classe} alert-dismissible fade show' role='alert'>
                {$message}
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            </div>";
}

// Protège contre les XSS
function nettoyer($texte) {
    return htmlspecialchars($texte, ENT_QUOTES, 'UTF-8');
}

// Formate une date
function formater_date($date, $format = 'd/m/Y') {
    if (empty($date)) return '-';
    return date($format, strtotime($date));
}

// Traduit les rôles en français
function traduire_role($role) {
    $roles = [
        'etudiant' => 'Étudiant',
        'professeur' => 'Professeur',
        'coordinateur' => 'Coordinateur',
        'directeur' => 'Directeur',
        'assistante' => 'Assistante'
    ];
    return $roles[$role] ?? $role;
}
?>