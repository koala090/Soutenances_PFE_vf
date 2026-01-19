<?php
// /Soutenances_PFE/salles/modifier.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

demarrer_session();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'assistante') {
    header('Location: /Soutenances_PFE/login.php');
    exit;
}

$user = get_user_info();
$message = '';
$error = '';

// Récupérer l'ID de la salle
if (!isset($_GET['id'])) {
    header('Location: /Soutenances_PFE/salles/liste.php');
    exit;
}

$id = intval($_GET['id']);

// Récupérer les informations de la salle
$stmt = $pdo->prepare("SELECT * FROM salles WHERE id = ?");
$stmt->execute([$id]);
$salle = $stmt->fetch();

if (!$salle) {
    header('Location: /Soutenances_PFE/salles/liste.php');
    exit;
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $nom = trim($_POST['nom'] ?? '');
        $batiment = trim($_POST['batiment'] ?? '');
        $etage = trim($_POST['etage'] ?? '');
        $capacite = intval($_POST['capacite'] ?? 0);
        $equipements = json_encode($_POST['equipements'] ?? []);
        $disponible = isset($_POST['disponible']) ? 1 : 0;
        
        if (empty($nom) || empty($batiment) || empty($etage) || $capacite < 1) {
            throw new Exception("Tous les champs obligatoires doivent être remplis");
        }
        
        $stmt = $pdo->prepare("UPDATE salles SET nom = ?, batiment = ?, etage = ?, capacite = ?, equipements = ?, disponible = ? WHERE id = ?");
        $stmt->execute([$nom, $batiment, $etage, $capacite, $equipements, $disponible, $id]);
        
        $message = "✅ Salle modifiée avec succès !";
        
        // Rafraîchir les données
        $stmt = $pdo->prepare("SELECT * FROM salles WHERE id = ?");
        $stmt->execute([$id]);
        $salle = $stmt->fetch();
        
    } catch (Exception $e) {
        $error = "❌ " . $e->getMessage();
    }
}

$equipements_existants = json_decode($salle['equipements'], true) ?? [];

// Vérifier si la salle est utilisée
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM soutenances WHERE salle_id = ? AND date_soutenance >= CURDATE()");
$stmt->execute([$id]);
$usage = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier la Salle - PFE Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; 
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        
        .form-container { 
            background: white; 
            border-radius: 20px; 
            padding: 2.5rem; 
            max-width: 650px; 
            width: 100%; 
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .form-header { 
            text-align: center; 
            margin-bottom: 2rem; 
        }
        
        .form-header .icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            margin: 0 auto 1rem;
        }
        
        .form-header h1 { 
            color: #f59e0b; 
            font-size: 1.75rem; 
            font-weight: 700;
            margin-bottom: 0.5rem; 
        }
        
        .form-header p { 
            color: #64748b; 
            font-size: 0.875rem;
        }
        
        .form-group { 
            margin-bottom: 1.5rem; 
        }
        
        .form-label { 
            display: block; 
            margin-bottom: 0.5rem; 
            color: #0f172a; 
            font-weight: 600; 
            font-size: 0.875rem; 
        }
        
        .required { 
            color: #ef4444; 
        }
        
        .form-control { 
            width: 100%; 
            padding: 0.875rem 1rem; 
            border: 2px solid #e2e8f0; 
            border-radius: 10px; 
            font-size: 0.9375rem; 
            transition: all 0.3s;
        }
        
        .form-control:focus { 
            outline: none; 
            border-color: #f59e0b; 
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1);
        }
        
        .input-group {
            position: relative;
        }
        
        .input-group i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1.125rem;
        }
        
        .input-group .form-control {
            padding-left: 2.75rem;
        }
        
        .checkbox-grid { 
            display: grid; 
            grid-template-columns: repeat(2, 1fr); 
            gap: 0.75rem; 
            background: #fef3c7; 
            padding: 1rem; 
            border-radius: 10px; 
        }
        
        .checkbox-label { 
            display: flex; 
            align-items: center; 
            gap: 0.625rem; 
            cursor: pointer; 
            padding: 0.625rem; 
            border-radius: 8px; 
            transition: background 0.2s;
            font-size: 0.875rem;
        }
        
        .checkbox-label:hover { 
            background: #fde68a; 
        }
        
        .checkbox-label input[type="checkbox"] { 
            width: 18px; 
            height: 18px; 
            cursor: pointer; 
            accent-color: #f59e0b;
        }
        
        .switch-container { 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            background: #fef3c7; 
            padding: 1rem; 
            border-radius: 10px; 
        }
        
        .switch-container label {
            font-weight: 500;
            color: #0f172a;
        }
        
        .switch { 
            position: relative; 
            display: inline-block; 
            width: 50px; 
            height: 26px; 
        }
        
        .switch input { 
            opacity: 0; 
            width: 0; 
            height: 0; 
        }
        
        .slider { 
            position: absolute; 
            cursor: pointer; 
            top: 0; 
            left: 0; 
            right: 0; 
            bottom: 0; 
            background-color: #cbd5e0; 
            transition: .4s; 
            border-radius: 34px; 
        }
        
        .slider:before { 
            position: absolute; 
            content: ""; 
            height: 20px; 
            width: 20px; 
            left: 3px; 
            bottom: 3px; 
            background-color: white; 
            transition: .4s; 
            border-radius: 50%; 
        }
        
        input:checked + .slider { 
            background-color: #10b981; 
        }
        
        input:checked + .slider:before { 
            transform: translateX(24px); 
        }
        
        .form-actions { 
            display: flex; 
            gap: 1rem; 
            margin-top: 2rem; 
        }
        
        .btn { 
            flex: 1; 
            padding: 0.875rem 1.5rem; 
            border: none; 
            border-radius: 10px; 
            font-size: 0.9375rem; 
            font-weight: 600; 
            cursor: pointer; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            gap: 0.5rem; 
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .btn-primary { 
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); 
            color: white; 
        }
        
        .btn-primary:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.4); 
        }
        
        .btn-secondary { 
            background: #e2e8f0; 
            color: #475569; 
        }
        
        .btn-secondary:hover { 
            background: #cbd5e0; 
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
            width: 100%;
            margin-top: 1.5rem;
        }
        
        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.4);
        }
        
        .alert { 
            padding: 1rem 1.25rem; 
            border-radius: 10px; 
            margin-bottom: 1.5rem; 
            display: flex; 
            align-items: center; 
            gap: 0.75rem; 
            font-size: 0.875rem;
        }
        
        .alert-success { 
            background: #d1fae5; 
            color: #065f46; 
            border-left: 4px solid #10b981; 
        }
        
        .alert-danger { 
            background: #fee2e2; 
            color: #991b1b; 
            border-left: 4px solid #ef4444; 
        }
        
        .info-box { 
            background: #dbeafe; 
            border-left: 4px solid #0284c7; 
            padding: 1rem; 
            border-radius: 10px; 
            margin-bottom: 1.5rem; 
            font-size: 0.875rem;
            color: #1e40af;
        }
        
        .warning-box {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            color: #92400e;
        }
        
        .info-box i, .warning-box i { 
            margin-right: 0.5rem; 
        }
        
        .delete-section {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px solid #f1f5f9;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <div class="form-header">
            <div class="icon">
                <i class="bi bi-pencil-square"></i>
            </div>
            <h1>Modifier la Salle</h1>
            <p><?= nettoyer($salle['nom']) ?></p>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle-fill"></i>
                <span><?= $message ?></span>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-circle-fill"></i>
                <span><?= $error ?></span>
            </div>
        <?php endif; ?>
        
        <?php if ($usage['count'] > 0): ?>
            <div class="warning-box">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <strong>Attention :</strong> Cette salle est utilisée dans <?= $usage['count'] ?> soutenance(s) à venir.
            </div>
        <?php endif; ?>
        
        <div class="info-box">
            <i class="bi bi-info-circle-fill"></i>
            Les modifications affecteront toutes les soutenances futures dans cette salle
        </div>
        
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Nom de la salle <span class="required">*</span></label>
                <div class="input-group">
                    <i class="bi bi-building"></i>
                    <input type="text" name="nom" class="form-control" required value="<?= nettoyer($salle['nom']) ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Bâtiment <span class="required">*</span></label>
                <div class="input-group">
                    <i class="bi bi-building-fill-gear"></i>
                    <input type="text" name="batiment" class="form-control" required value="<?= nettoyer($salle['batiment']) ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Étage <span class="required">*</span></label>
                <div class="input-group">
                    <i class="bi bi-layers-fill"></i>
                    <input type="text" name="etage" class="form-control" required value="<?= nettoyer($salle['etage']) ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Capacité (nombre de places) <span class="required">*</span></label>
                <div class="input-group">
                    <i class="bi bi-people-fill"></i>
                    <input type="number" name="capacite" class="form-control" required min="1" value="<?= $salle['capacite'] ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label"><i class="bi bi-tools"></i> Équipements disponibles</label>
                <div class="checkbox-grid">
                    <label class="checkbox-label">
                        <input type="checkbox" name="equipements[]" value="Projecteur" <?= in_array('Projecteur', $equipements_existants) ? 'checked' : '' ?>>
                        <span>Projecteur</span>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="equipements[]" value="Tableau blanc" <?= in_array('Tableau blanc', $equipements_existants) ? 'checked' : '' ?>>
                        <span>Tableau blanc</span>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="equipements[]" value="Visioconférence" <?= in_array('Visioconférence', $equipements_existants) ? 'checked' : '' ?>>
                        <span>Visioconférence</span>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="equipements[]" value="Climatisation" <?= in_array('Climatisation', $equipements_existants) ? 'checked' : '' ?>>
                        <span>Climatisation</span>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="equipements[]" value="Ordinateur" <?= in_array('Ordinateur', $equipements_existants) ? 'checked' : '' ?>>
                        <span>Ordinateur</span>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="equipements[]" value="Microphone" <?= in_array('Microphone', $equipements_existants) ? 'checked' : '' ?>>
                        <span>Microphone</span>
                    </label>
                </div>
            </div>
            
            <div class="form-group">
                <div class="switch-container">
                    <label><i class="bi bi-check-circle-fill"></i> Salle disponible</label>
                    <label class="switch">
                        <input type="checkbox" name="disponible" <?= $salle['disponible'] ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>
            </div>
            
            <div class="form-actions">
                <a href="/Soutenances_PFE/salles/liste.php" class="btn btn-secondary">
                    <i class="bi bi-x-lg"></i> Annuler
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Enregistrer
                </button>
            </div>
        </form>
        
        <div class="delete-section">
            <button onclick="confirmDelete()" class="btn btn-danger">
                <i class="bi bi-trash"></i> Supprimer cette salle
            </button>
        </div>
    </div>
    
    <script>
        function confirmDelete() {
            if (confirm('Êtes-vous sûr de vouloir supprimer définitivement cette salle ? Cette action est irréversible.')) {
                window.location.href = '/Soutenances_PFE/salles/gestion.php?action=supprimer&id=<?= $id ?>';
            }
        }
        
        // Auto-hide success message
        <?php if ($message): ?>
        setTimeout(() => {
            const alert = document.querySelector('.alert-success');
            if (alert) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            }
        }, 3000);
        <?php endif; ?>
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
