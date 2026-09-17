' ==========================================================
' Analise de VENDA das conversas do WhatsApp (IA local)
'
' Roda oculto, em segundo plano, sem janela de console.
' Le as conversas novas ou alteradas e grava o veredito
' (fechou a venda ou nao) na tabela conversa_venda.
'
' Depende do servidor Ollama no ar (tarefa "ProjetoCRM Ollama IA Local").
' Se a IA estiver fora, o script sai quieto sem erro.
' ==========================================================
Option Explicit

Dim shell, fso, root, logDir, command
Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

root = "C:\xampp\htdocs\site\projetocrm"
logDir = root & "\storage\logs"

If Not fso.FolderExists(logDir) Then
    fso.CreateFolder(logDir)
End If

' 8 conversas por rodada: na CPU cada uma leva ~10s, entao a rodada fica
' em torno de 1-2 minutos. Sobra tempo de sobra ate a proxima.
command = "cmd.exe /d /c """"C:\xampp\php\php.exe"" """ & root & "\scripts\analisar_vendas.php"" 8 >> """ & logDir & "\analise_vendas.log"" 2>&1"""

shell.CurrentDirectory = root
shell.Run command, 0, True

WScript.Quit 0
