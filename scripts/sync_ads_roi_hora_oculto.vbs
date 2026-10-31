' ==========================================================
' sync_ads_roi_hora_oculto.vbs
' Roda o sync de ROI (Meta/Google Ads) sem janela de console,
' para a tarefa agendada "Projetocrm AdsROI Sync Horario"
' (de hora em hora, 06:00-23:00).
'
' Usa janela de 7 dias: leve o bastante para rodar de hora em hora
' e manter o dia corrente atualizado. O fechamento de 30 dias
' continua na tarefa diaria "Projetocrm AdsROI Sync" (08:00).
' ==========================================================
Option Explicit

Dim shell
Set shell = CreateObject("WScript.Shell")

shell.Run """C:\xampp\php\php.exe"" ""C:\xampp\htdocs\site\projetocrm\scripts\sync_ads_roi.php"" 7", 0, True
