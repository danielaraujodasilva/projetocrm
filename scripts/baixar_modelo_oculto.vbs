' ==========================================================
' Baixa modelo do LM Studio como tarefa agendada.
'
' POR QUE TAREFA (e nao comando direto)
' O `lms get` morre se a sessao que chamou for encerrada - o download
' fica em 0 MB. Rodando como tarefa agendada, ele sobrevive a queda de
' sessao e retoma de onde parou.
'
' Progresso em: projetocrm\storage\logs\download_modelo.json
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

command = "cmd.exe /d /c """"C:\xampp\php\php.exe"" """ & root & "\scripts\baixar_modelo.php"" ""qwen/qwen3-30b-a3b"" >> """ & logDir & "\download_modelo.log"" 2>&1"

shell.CurrentDirectory = root
shell.Run command, 0, True

WScript.Quit 0
