' ==========================================================
' ads_conversao_offline_oculto.vbs
' Roda o envio de conversoes offline ao Google Ads sem janela de
' console, para a tarefa "Projetocrm AdsConversao Offline" (diaria, 09:00).
' ==========================================================
Option Explicit

Dim shell
Set shell = CreateObject("WScript.Shell")

shell.Run """C:\xampp\php\php.exe"" ""C:\xampp\htdocs\site\projetocrm\tools\ads_conversao_offline.php"" --apply --dias=30", 0, True
