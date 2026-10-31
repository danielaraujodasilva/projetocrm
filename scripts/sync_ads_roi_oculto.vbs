' ==========================================================
' sync_ads_roi_oculto.vbs
' Roda o sync de ROI (Meta/Google Ads) sem janela de console,
' para a tarefa agendada "Projetocrm AdsROI Sync" (diaria, 08:00).
' ==========================================================
Option Explicit

Dim shell
Set shell = CreateObject("WScript.Shell")

shell.Run """C:\xampp\php\php.exe"" ""C:\xampp\htdocs\site\projetocrm\scripts\sync_ads_roi.php"" 30", 0, True
