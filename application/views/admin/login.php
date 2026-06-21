<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYSTEM COMMAND // Auth</title>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #000; color: #22c55e; font-family: 'JetBrains Mono', monospace; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .box { border: 1px solid #22c55e; padding: 2.5rem; width: 100%; max-width: 400px; }
        .title { font-size: 0.75rem; font-weight: 700; letter-spacing: 0.2em; margin-bottom: 1.5rem; text-align: center; border-bottom: 1px solid #166534; padding-bottom: 1rem; }
        .field { margin-bottom: 1rem; }
        .field label { display: block; font-size: 0.625rem; letter-spacing: 0.15em; margin-bottom: 0.375rem; color: #4ade80; }
        .field input { width: 100%; padding: 0.625rem 0.75rem; background: #111; border: 1px solid #166534; color: #22c55e; font-family: inherit; font-size: 0.75rem; outline: none; }
        .field input:focus { border-color: #22c55e; }
        .btn { width: 100%; padding: 0.625rem; background: #22c55e; color: #000; font-family: inherit; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.1em; border: none; cursor: pointer; margin-top: 0.5rem; }
        .btn:hover { background: #16a34a; }
        .err { color: #ef4444; font-size: 0.625rem; margin-top: 0.25rem; text-transform: uppercase; }
        .flash { border: 1px solid #991b1b; background: #1c0a0a; color: #ef4444; font-size: 0.625rem; padding: 0.5rem 0.75rem; margin-bottom: 1rem; text-transform: uppercase; letter-spacing: 0.1em; }
        .foot { font-size: 0.5rem; color: #166534; text-align: center; margin-top: 1.5rem; letter-spacing: 0.15em; }
    </style>
</head>
<body>
    <div class="box">
        <div class="title">SYSTEM COMMAND // AUTHENTICATION REQUIRED</div>

        <?php if ($this->session->flashdata('error')): ?>
            <div class="flash"><?= $this->session->flashdata('error') ?></div>
        <?php endif; ?>

        <?= form_open('control-panel') ?>
            <div class="field">
                <label>USERNAME</label>
                <input type="text" name="username" required autocomplete="off" placeholder="root">
            </div>
            <div class="field">
                <label>PASSWORD</label>
                <input type="password" name="password" required placeholder="********">
            </div>
            <button type="submit" class="btn">ACCESS SYSTEM</button>
        <?= form_close() ?>

        <div class="foot">SYNAPSE // ROOT ACCESS v1.0</div>
    </div>
</body>
</html>
