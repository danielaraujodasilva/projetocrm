' ==========================================================
' LM Studio - servidor de IA local para a analise de venda
'
' POR QUE LM STUDIO E NAO OLLAMA
' Testado nesta maquina (Radeon RX 5500, 8 GB): o Ollama NAO consegue
' usar a GPU. Nem por Vulkan nem por ROCm ele detecta a placa
' (total_vram = 0 B) e cai sempre para CPU (~6s por conversa).
' O LM Studio tem backend Vulkan proprio, usa a GPU de verdade
' (6176 MB de VRAM em uso) e responde em ~1,2s - 5x mais rapido.
'
' Consumido por projetocrm/app/venda_ia.php via http://127.0.0.1:1234
' ==========================================================
Option Explicit

Dim shell, fso, logDir, lmExe, command
Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

logDir = "C:\Users\server_spd\Documents\whatsapp-origin-bridge\dados"
If Not fso.FolderExists(logDir) Then
    fso.CreateFolder(logDir)
End If

lmExe = "C:\Users\server_spd\AppData\Local\Programs\LM Studio\LM Studio.exe"
If Not fso.FileExists(lmExe) Then
    WScript.Quit 1
End If

' Abre o LM Studio (ele sobe o servidor na porta 1234 quando configurado).
' 0 = janela oculta; False = nao aguardar (o app fica rodando em segundo plano).
shell.Run """" & lmExe & """", 0, False

WScript.Quit 0
