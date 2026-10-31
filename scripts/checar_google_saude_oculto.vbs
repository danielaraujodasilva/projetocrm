' ==========================================================
' checar_google_saude_oculto.vbs
' Confere a saude do Google Ads sem abrir janela de console,
' para a tarefa agendada "Projetocrm Google Saude 30min".
' ==========================================================
Option Explicit

Dim shell
Set shell = CreateObject("WScript.Shell")

shell.Run """C:\xampp\php\php.exe"" ""C:\xampp\htdocs\site\projetocrm\scripts\checar_google_saude.php""", 0, True
