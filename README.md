# Système de Gestion des Soutenances de Projets de Fin d’Études (PFE)

## 📘 Présentation du projet
Ce projet consiste en la conception et le développement d’une **application web complète de gestion des soutenances de Projets de Fin d’Études (PFE)**.  
Il couvre l’ensemble du processus académique, depuis l’inscription des projets jusqu’à la génération automatique des documents officiels liés à la soutenance.

---

## 🎯 Objectifs pédagogiques

- Centraliser la gestion des projets PFE
- Automatiser l’affectation des encadrants
- Gérer les disponibilités des enseignants
- Planifier manuellement et automatiquement les soutenances
- Constituer les jurys selon des règles académiques
- Générer automatiquement :
  - Convocations de soutenance (PDF)
  - Procès-verbaux (PV) de soutenance (PDF)
- Mettre en place une application sécurisée multi-rôles

---

## 👥 Rôles utilisateurs

L’application repose sur une gestion des accès par rôles (RBAC) :

- Étudiant  
- Professeur / Encadrant  
- Coordinateur de filière  
- Assistante pédagogique  
- Directeur  

Chaque rôle dispose d’un espace dédié avec des fonctionnalités spécifiques.

---
## 🗂️ Structure du dépôt

```text
Soutenances_PFE/
│   index.php
│   login.php
│   logout.php
│   README.md
│
├── admin/
│   └── fix_annee_universitaire.php
│
├── config/
│   └── database.php
│
├── css/
│   │   style.css
│   └── images/
│       ├── euromed.jpg
│
├── dashboards/
│   ├── assistante.php
│   ├── coordinateur.php
│   ├── directeur.php
│   ├── etudiant.php
│   └── professeur.php
│
├── database/
│   ├── schema.sql
│   └── data.sql
│
├── documents/
│   ├── archivage.php
│   ├── attestation.php
│   ├── convocations.php
│   ├── dossiers.php
│   ├── feuille-emargement.php
│   ├── grille-evaluation.php
│   └── pv.php
│
├── fpdf/
│   ├── fpdf.php
│   ├── fpdf.css
│   └── font/
│
├── includes/
│   ├── header.php
│   ├── footer.php
│   └── functions.php
│
├── jurys/
│   ├── constituer.php
│   ├── constituer_auto.php
│   ├── equilibrer.php
│   ├── liste-soutenances.php
│   ├── mes-jurys.php
│   └── saisir_note.php
│
├── planning/
│   ├── ma-soutenance.php
│   ├── periode.php
│   ├── planifier.php
│   ├── planifier_auto.php
│   ├── planningglobal.php
│   ├── saisir_disponibilites.php
│   ├── suivi_disponibilites.php
│   └── voir_planning.php
│
├── projets/
│   ├── affectation.php
│   ├── affectation_auto.php
│   ├── diagnostic_messages.php
│   ├── inscription.php
│   ├── liste.php
│   ├── messagerie.php
│   ├── upload_rapport.php
│   ├── valider_rapport.php
│   └── view_rapport.php
│
├── salles/
│   ├── ajouter.php
│   ├── gestion.php
│   ├── liste.php
│   └── modifier.php
│
└── uploads/
    └── convocations/
        ├── CONVOCATION_20260120_1.pdf
        └── CONVOCATION_20260121_1.pdf

```

---

## 🛠️ Technologies utilisées

### Backend
- PHP (PDO – Programmation Orientée Objet)
- MySQL 

### Frontend
- HTML5
- CSS3


### Environnement & outils
- Apache
- phpMyAdmin
- Git & GitHub
- FPDF (génération de fichiers PDF)
- DNS local (BIND9)

---

## 🌐 Configuration DNS & Apache
Dans le cadre de l’infrastructure réseau du projet :

Installation et configuration complète du serveur DNS (BIND9)

Mise en place du serveur Apache

Création et gestion du domaine local suivant :

soutenances.siham.local

Ce domaine permet un accès interne sécurisé à la plateforme web de gestion des soutenances.

---
## 👨‍👩‍👧‍👦 Répartition du travail (Projet en groupe)
### 👤 Étudiant 1: LYZOUL SIHAM
Configuration complète du DNS (BIND9)

Configuration du serveur Apache

Création du domaine subnance.cia.local

Authentification et gestion des sessions

Gestion des utilisateurs

Dashboards multi-rôles

Sécurité et middlewares

### 👤 Étudiant 2: Fathi Abderrahman
Inscription des projets

Affectation manuelle et automatique des encadrants

Upload et validation des rapports

Messagerie interne

Suivi d’avancement des projets

### 👤 Étudiant 3: BOUTAOUAR Hadil
Gestion des salles

Saisie des disponibilités des enseignants

Planification manuelle des soutenances

Algorithme de planification automatique

Détection des conflits

### 👤 Étudiant 4: AIT OUSSAYER Mariyem
Constitution des jurys

Équilibrage automatique des charges

Génération des convocations PDF

Génération des PV de soutenance

Saisie des notes finales

---
## 🔐 Sécurité
Sessions sécurisées

Gestion des rôles (RBAC)

Requêtes préparées (PDO)

Validation des fichiers uploadés

Protection basique contre les attaques courantes

---

## ⬇️ Téléchargement
Le projet peut être récupéré via :

Code → Download ZIP sur GitHub

ou via la commande :

git clone https://github.com/koala090/Soutenances_PFE_vf.git
