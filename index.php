<?php
session_start();

// Configuration
$upload_dir = 'submissions/';
$participants_file = 'participants.json';
$submissions_file = 'submissions.json';
$max_submissions = 100;
$deadline = strtotime('2026-01-18 00:00:00');

// Créer les dossiers nécessaires
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Fonction pour charger les données
function loadData($file) {
    if (file_exists($file)) {
        $json = file_get_contents($file);
        return json_decode($json, true) ?: [];
    }
    return [];
}

// Fonction pour sauvegarder les données
function saveData($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

// Générer un ID anonyme
function generateAnonymousId() {
    return 'P' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
}

// Vérifier si la date limite est dépassée
$is_closed = time() > $deadline;

// SECTION 1: Enregistrement du participant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register']) && !$is_closed) {
    $errors = [];
    $name = trim($_POST['name'] ?? '');
    $student_id = trim($_POST['student_id'] ?? '');
    
    if (empty($name)) $errors[] = "Le nom est requis.";
    if (empty($student_id)) $errors[] = "Le numero d'etudiant est requis.";
    
    if (empty($errors)) {
        $participants = loadData($participants_file);
        
        // Vérifier si l'étudiant existe déjà
        $existing = null;
        foreach ($participants as $p) {
            if ($p['student_id'] === $student_id) {
                $existing = $p;
                break;
            }
        }
        
        if ($existing) {
            $_SESSION['anonymous_id'] = $existing['anonymous_id'];
            $_SESSION['message'] = 'PARTICIPANT DEJA ENREGISTRE';
        } else {
            // Vérifier la limite
            if (count($participants) >= $max_submissions) {
                $errors[] = "LIMITE DE 100 PARTICIPANTS ATTEINTE";
            } else {
                $anonymous_id = generateAnonymousId();
                $participants[] = [
                    'anonymous_id' => $anonymous_id,
                    'name' => $name,
                    'student_id' => $student_id,
                    'registered_at' => date('Y-m-d H:i:s')
                ];
                saveData($participants_file, $participants);
                $_SESSION['anonymous_id'] = $anonymous_id;
                $_SESSION['message'] = 'ENREGISTREMENT REUSSI';
            }
        }
        
        if (empty($errors)) {
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}

// SECTION 2: Soumission anonyme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_code']) && !$is_closed) {
    $errors = [];
    $anonymous_id = trim($_POST['anonymous_id'] ?? '');
    
    if (empty($anonymous_id)) {
        $errors[] = "L'identifiant anonyme est requis.";
    } else {
        // Vérifier que l'ID existe
        $participants = loadData($participants_file);
        $valid_id = false;
        foreach ($participants as $p) {
            if ($p['anonymous_id'] === $anonymous_id) {
                $valid_id = true;
                break;
            }
        }
        
        if (!$valid_id) {
            $errors[] = "Identifiant anonyme invalide.";
        }
    }
    
    if (isset($_FILES['zip_file']) && $_FILES['zip_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['zip_file'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($file_extension !== 'zip') {
            $errors[] = "Seuls les fichiers ZIP sont acceptes.";
        }
        
        if ($file['size'] > 500 * 1024 * 1024) {
            $errors[] = "Fichier trop volumineux (max 500MB).";
        }
    } else {
        $errors[] = "Veuillez selectionner un fichier ZIP.";
    }
    
    if (empty($errors)) {
        $submissions = loadData($submissions_file);
        $timestamp = time();
        $new_filename = $anonymous_id . '_' . $timestamp . '.zip';
        $target_path = $upload_dir . $new_filename;
        
        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            // Marquer les anciennes soumissions comme obsolètes
            foreach ($submissions as $key => $sub) {
                if ($sub['anonymous_id'] === $anonymous_id) {
                    $submissions[$key]['is_latest'] = false;
                }
            }
            
            // Ajouter la nouvelle soumission
            $submissions[] = [
                'anonymous_id' => $anonymous_id,
                'filename' => $new_filename,
                'timestamp' => date('Y-m-d H:i:s'),
                'is_latest' => true
            ];
            
            saveData($submissions_file, $submissions);
            $_SESSION['submit_success'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}

// Télécharger toutes les dernières soumissions en ZIP
if (isset($_GET['download_all']) && isset($_GET['admin']) && $_GET['admin'] === 'admin123') {
    $submissions = loadData($submissions_file);
    $latest = array();
    foreach ($submissions as $s) {
        if (isset($s['is_latest']) && $s['is_latest'] === true) {
            $latest[] = $s;
        }
    }
    
    if (!empty($latest)) {
        $zip = new ZipArchive();
        $zip_filename = 'toutes_soumissions_' . date('Y-m-d_H-i-s') . '.zip';
        $zip_path = sys_get_temp_dir() . '/' . $zip_filename;
        
        if ($zip->open($zip_path, ZipArchive::CREATE) === TRUE) {
            foreach ($latest as $sub) {
                $file_path = $upload_dir . $sub['filename'];
                if (file_exists($file_path)) {
                    $zip->addFile($file_path, $sub['anonymous_id'] . '.zip');
                }
            }
            $zip->close();
            
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
            header('Content-Length: ' . filesize($zip_path));
            readfile($zip_path);
            unlink($zip_path);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="/dev-1931969/morrisJAM26/">
    <title>morrisJAM26</title>
    <!-- Fixed CSS path - remove index.php/ from path -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect fill='%23000' width='100' height='100'/><text y='70' x='50' text-anchor='middle' fill='%2300ff00' font-size='60' font-family='monospace'>M</text></svg>">

    <link href="style.css" type="text/css" rel="stylesheet" />
</head>
<body class="crt">
    <div class="scanline"></div>
    <div class="container">
        

    
        <!-- En-tête avec timer -->
        <div class="header">
           <div>
        <div class="logo-container">
        <div class="main-text">morrisJAM</div>
        <div class="sub-text">26</div></div>
    </div>

            <h1>CONCOURS DE PROGRAMMATION</h1>
            
            <?php if ($is_closed): ?>
                <div class="timer danger">CONCOURS TERMINE</div>
            <?php else: ?>
                <div style="font-size: 22px; margin: 10px 0;">DATE LIMITE: 18 JANVIER 2026 - 00:00</div>
                <div class="timer" id="countdown">CHARGEMENT...</div>
            <?php endif; ?>
            
            <div class="stats">
                <div class="stat-item">
                    <div class="stat-value"><?= count(loadData($participants_file)) ?></div>
                    <div>PARTICIPANTS</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php
                        $subs = loadData($submissions_file);
                        $count = 0;
                        foreach ($subs as $s) {
                            if (isset($s['is_latest']) && $s['is_latest'] === true) {
                                $count++;
                            }
                        }
                        echo $count;
                    ?></div>
                    <div>SOUMISSIONS</div>
                </div>
                
            </div>
        </div>
        
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-success">
                <strong><?= htmlspecialchars($_SESSION['message']) ?></strong>
                <div class="submission-id"><?= htmlspecialchars($_SESSION['anonymous_id']) ?></div>
                <div style="text-align: center; margin-top: 10px;">
                    [!] CONSERVEZ PRECIEUSEMENT CET IDENTIFIANT [!]<br>
                    Vous en aurez besoin pour soumettre votre projet.
                </div>
            </div>
            <?php unset($_SESSION['message']); unset($_SESSION['anonymous_id']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['submit_success'])): ?>
            <div class="alert alert-success">
                <strong>[OK] SOUMISSION ENREGISTREE AVEC SUCCES</strong><br>
                Votre projet a ete recu. Vous pouvez soumettre<br>
                une nouvelle version a tout moment avant la date limite.
            </div>
            <?php unset($_SESSION['submit_success']); ?>
        <?php endif; ?>
        
        <?php if (isset($errors) && !empty($errors)): ?>
            <div class="alert alert-error">
                <strong>[ERREUR] PROBLEME DETECTE:</strong><br>
                <?php foreach ($errors as $error): ?>
                    > <?= htmlspecialchars($error) ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($is_closed): ?>
            <div class="alert alert-error">
                <strong>[SYSTEME FERME]</strong><br>
                La date limite de soumission est depassee.<br>
                Le concours est maintenant termine.
            </div>
        <?php else: ?>
        
        <!-- SECTION 1: Enregistrement -->
        <div class="section">
            <div class="section-title">[SECTION 1] ENREGISTREMENT</div>
            <div class="info-box">
                > Entrez vos informations pour recevoir un identifiant anonyme<br>
                > Cet identifiant sera utilise pour soumettre votre projet<br>
                > Si vous etes deja enregistre, re-entrez vos infos pour recuperer votre ID
            </div>
            
            <form method="POST">
                <div class="form-group">
                    <label for="participant_name">> NOM COMPLET / EQUIPE:</label>
                    <input type="text" 
                           id="participant_name" 
                           name="name" 
                           required 
                           autocomplete="name"
                           placeholder="Ex: Jean Tremblay">
                </div>
                
                <div class="form-group">
                    <label for="student_id">> NUMERO D'ETUDIANT:</label>
                    <input type="text" 
                           id="student_id" 
                           name="student_id" 
                           required 
                           autocomplete="off"
                           placeholder="Ex: 123456">
                </div>
                
                <button type="submit" name="register" class="btn">
                    [ OBTENIR ID ANONYME ]
                </button>
            </form>
        </div>
        
        <!-- SECTION 2: Soumission anonyme -->
        <div class="section">
            <div class="section-title">[SECTION 2] SOUMISSION ANONYME</div>
            <div class="info-box">
                > Utilisez votre identifiant anonyme pour soumettre votre projet<br>
                > Format requis: Fichier ZIP contenant (Code + Executable + README)<br>
                > Vous pouvez soumettre plusieurs fois - seule la derniere sera evaluee
            </div>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="anonymous_id">> IDENTIFIANT ANONYME:</label>
                    <input type="text" 
                           id="anonymous_id" 
                           name="anonymous_id" 
                           required 
                           autocomplete="off"
                           placeholder="Ex: P1234" 
                           pattern="P[0-9]{4}" 
                           title="Format: P suivi de 4 chiffres">
                </div>
                
                <div class="form-group">
                    <label for="zip_file">> FICHIER ZIP DU PROJET (Code + Executable + README):</label>
                    <input type="file" 
                           id="zip_file" 
                           name="zip_file" 
                           accept=".zip" 
                           required>
                </div>
                
                <button type="submit" name="submit_code" class="btn">
                    [ SOUMETTRE MON PROJET ]
                </button>
            </form>
        </div>
        
        <?php endif; ?>
        
        <!-- SECTION ADMIN (mot de passe: admin123) -->
        <?php if (isset($_GET['admin']) && $_GET['admin'] === 'admin123'): ?>
            <div class="section-title" style="color: #ffff00; text-shadow: 0 0 10px #ffff00;">
                [PANNEAU ADMINISTRATEUR]
            </div>
            
            <h3 style="color: #ffff00; font-size: 28px; margin: 20px 0;">DERNIERES SOUMISSIONS:</h3>
            
            <?php
            $submissions = loadData($submissions_file);
            $latest_submissions = array_filter($submissions, function($s) {
                return isset($s['is_latest']) && $s['is_latest'] === true;
            });
            
            if (empty($latest_submissions)):
            ?>
                <p style="color: #ffff00;">Aucune soumission pour le moment.</p>
            <?php else: ?>
                <?php foreach ($latest_submissions as $sub): ?>
                    <div style="border: 2px solid #ffff00; padding: 15px; margin: 10px 0; background: rgba(255, 255, 0, 0.05);">
                        <strong style="color: #ffff00;">ID: <?= htmlspecialchars($sub['anonymous_id']) ?></strong><br>
                        Date: <?= htmlspecialchars($sub['timestamp']) ?><br>
                        <a href="<?= $upload_dir . htmlspecialchars($sub['filename']) ?>" 
                           class="download-btn" download>
                            [ TELECHARGER ZIP ]
                        </a>
                    </div>
                <?php endforeach; ?>
                
                <div style="margin-top: 30px;">
                    <a href="?admin=admin123&download_all=1" class="download-btn" style="font-size: 24px;">
                        [ TELECHARGER TOUTES LES DERNIERES SOUMISSIONS ]
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
    </div>
    
    <script>
        // Timer countdown
        const deadline = new Date('2026-01-18T00:00:00').getTime();
        
        function updateCountdown() {
            const now = new Date().getTime();
            const distance = deadline - now;
            
            if (distance < 0) {
                document.getElementById('countdown').innerHTML = 'TEMPS ECOULE';
                document.getElementById('countdown').className = 'timer danger';
                return;
            }
            
            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);
            
            const countdownEl = document.getElementById('countdown');
            countdownEl.innerHTML = 
                String(days).padStart(2, '0') + 'J : ' +
                String(hours).padStart(2, '0') + 'H : ' + 
                String(minutes).padStart(2, '0') + 'M : ' + 
                String(seconds).padStart(2, '0') + 'S';
            
            // Changer la couleur selon le temps restant
            if (days < 1) {
                countdownEl.className = 'timer danger';
            } else if (days < 3) {
                countdownEl.className = 'timer warning';
            } else {
                countdownEl.className = 'timer';
            }
        }
        
        // Mettre à jour chaque seconde
        if (document.getElementById('countdown')) {
            updateCountdown();
            setInterval(updateCountdown, 1000);
        }
    </script>
</body>
</html>