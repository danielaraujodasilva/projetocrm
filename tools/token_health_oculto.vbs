' Roda a checagem/renovacao de tokens sem abrir janela.
Set sh = CreateObject("WScript.Shell")
sh.Run """C:\xampp\php\php.exe"" ""C:\xampp\htdocs\site\projetocrm\tools\token_health.php""", 0, False
