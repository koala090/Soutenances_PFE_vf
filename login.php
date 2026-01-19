<?php
session_start();
require_once 'config/database.php';

$erreur = '';
$success = '';

// Message de déconnexion
if (isset($_GET['message']) && $_GET['message'] === 'deconnexion_reussie') {
    $success = "Vous avez été déconnecté avec succès";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $erreur = "Veuillez remplir tous les champs";
    } else {
        // Rechercher l'utilisateur
        $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE email = ? AND actif = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['mot_de_passe'])) {
            // Créer la session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_nom'] = $user['nom'];
            $_SESSION['user_prenom'] = $user['prenom'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_filiere'] = $user['filiere_id'];
            
            // Redirection selon le rôle - CORRECTION ICI
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
        } else {
            $erreur = "Email ou mot de passe incorrect";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - Gestion des Soutenances</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="bg-image"></div>
    
    <div class="container position-relative">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-5">
                <div class="card shadow-lg bg-white bg-opacity-90">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <i class="bi bi-mortarboard-fill text-primary" style="font-size: 3rem;"></i>
                            <h1 class="h3 mt-3 mb-2">Gestion des Soutenances</h1>
                            <p class="text-muted">Connectez-vous à votre compte</p>
                        </div>

                        <?php if ($erreur): ?>
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($erreur) ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success) ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="/Soutenances_PFE/login.php">
                            <div class="mb-3">
                                <label for="email" class="form-label">
                                    <i class="bi bi-envelope"></i> Adresse email
                                </label>
                                <input type="email" class="form-control form-control-lg" id="email" 
                                       name="email" placeholder="votre@email.com"
                                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
                            </div>

                            <div class="mb-4">
                                <label for="password" class="form-label">
                                    <i class="bi bi-lock"></i> Mot de passe
                                </label>
                                <input type="password" class="form-control form-control-lg" 
                                       id="password" name="password" placeholder="••••••••" required>
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                <i class="bi bi-box-arrow-in-right"></i> Se connecter
                            </button>
                        </form>
                    </div>
                </div>

                <div class="text-center mt-3">
                    <small class="text-muted">&copy; <?= date('Y') ?> - Gestion des Soutenances PFE</small>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>