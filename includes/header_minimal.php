<?php
// Ensure config is included before header
require_once __DIR__ . '/../config.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($app_name ?? 'Best Cobb Shop'); ?> - <?php echo htmlspecialchars($page_title ?? 'Mall POS'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/feather-icons"></script>
    <style>
        body {
            font-family: 'Poppins', sans-serif;
        }
    </style>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#1e3a8a', // Deep navy
                        secondary: '#f1f5f9', // Soft white
                        accent: '#d4af37', // Gold accent
                        neutral: '#64748b' // Slate
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-secondary text-gray-900">
    <div class="flex h-screen">
        <!-- Main Content -->
        <div class="flex-1 flex flex-col">
            <!-- Main Content Opening -->
            <main class="p-6 flex-1 overflow-hidden">