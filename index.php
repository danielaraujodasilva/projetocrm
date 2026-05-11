<?php

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

$projectName = 'Projeto CRM';
$now = date('d/m/Y H:i:s');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($projectName, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            color-scheme: light;
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f6f8;
            color: #17202a;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
        }

        main {
            width: min(680px, calc(100% - 32px));
            background: #fff;
            border: 1px solid #d9e0e7;
            border-radius: 8px;
            padding: 28px;
            box-shadow: 0 16px 40px rgba(20, 35, 50, 0.08);
        }

        h1 {
            margin: 0 0 10px;
            font-size: 28px;
        }

        p {
            margin: 8px 0;
            line-height: 1.5;
        }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 18px;
            padding: 10px 12px;
            border-radius: 6px;
            background: #e9f8ef;
            color: #176b3a;
            font-weight: 700;
        }

        .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #24a148;
        }
    </style>
</head>
<body>
<main>
    <h1><?= htmlspecialchars($projectName, ENT_QUOTES, 'UTF-8') ?></h1>
    <p>Alpha inicial online. O proximo passo sera criar o instalador, o login do gerente e o cadastro de estudios.</p>
    <p>Servidor respondeu em <?= htmlspecialchars($now, ENT_QUOTES, 'UTF-8') ?>.</p>
    <div class="status"><span class="dot"></span> Projeto separado pronto para Git</div>
</main>
</body>
</html>
