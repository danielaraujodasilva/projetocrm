' ==========================================================
' Religa conversas de WhatsApp orfas ao lead (retaguarda)
'
' POR QUE EXISTE
' A correcao principal faz a conversa nascer vinculada e a ponte de
' anuncio fechar o ciclo. Este job e a TERCEIRA linha de defesa:
' pega qualquer conversa que tenha escapado (importacao de historico,
' mensagem que chegou antes do lead) e liga pelo telefone.
'
' Roda oculto, sem janela. Se a IA local estiver fora, o script nao
' quebra: so pula a parte de analise e mantem o vinculo.
'
' Consumido por: scripts/vincular_conversas_orfaos.php
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

command = "cmd.exe /d /c """"C:\xampp\php\php.exe"" """ & root & "\scripts\vincular_conversas_orfaos.php"" >> """ & logDir & "\vincular_conversas.log"" 2>&1"""

shell.CurrentDirectory = root
shell.Run command, 0, True

WScript.Quit 0
