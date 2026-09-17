' ==========================================================
' Ollama para a analise de venda (IA local do CRM)
'
' Sobe o servidor do Ollama sem janela de console, com a GPU
' desligada. Motivo: a Radeon RX 5500 falha ao alocar memoria
' no Vulkan (driver AMD antigo) e o servico morre ao carregar
' qualquer modelo. Em CPU funciona.
'
' Consumido por: projetocrm/app/venda_ia.php (analise de venda
' das conversas do WhatsApp) via http://127.0.0.1:11434
' ==========================================================
Option Explicit

Dim shell, fso, logDir, ollamaExe, command
Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

logDir = "C:\Users\server_spd\Documents\whatsapp-origin-bridge\dados"
If Not fso.FolderExists(logDir) Then
    fso.CreateFolder(logDir)
End If

ollamaExe = "C:\Users\server_spd\AppData\Local\Programs\Ollama\ollama.exe"
If Not fso.FileExists(ollamaExe) Then
    WScript.Quit 1
End If

' GPU desligada: sem isso o carregamento do modelo falha.
command = "cmd.exe /d /c ""set OLLAMA_VULKAN=false&& set OLLAMA_GPU_OVERHEAD=0&& """ & ollamaExe & """ serve >> """ & logDir & "\ollama.log"" 2>&1"""

shell.CurrentDirectory = "C:\Users\server_spd\AppData\Local\Programs\Ollama"
' 0 = janela oculta, True = aguardar (a tarefa fica Running enquanto o servidor viver).
shell.Run command, 0, True

WScript.Quit 0
