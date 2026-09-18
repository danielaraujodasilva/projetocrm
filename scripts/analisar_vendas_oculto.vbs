' ==========================================================
' Analise de VENDA das conversas do WhatsApp (IA local)
'
' Roda oculto, em segundo plano, sem janela de console.
'
' ORCAMENTO DE TEMPO: a rodada trabalha ate 10 minutos e, se ainda
' houver fila, repete imediatamente (ate 3 passadas). Assim a analise
' alcanca o volume em vez de acumular atraso - importante porque a
' analise roda em CPU (~6s por conversa nesta maquina).
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

' 600s de orcamento, ate 3 passadas.
command = "cmd.exe /d /c """"C:\xampp\php\php.exe"" """ & root & "\scripts\analisar_vendas.php"" 600 3 >> """ & logDir & "\analise_vendas.log"" 2>&1"""

shell.CurrentDirectory = root
shell.Run command, 0, True

WScript.Quit 0
