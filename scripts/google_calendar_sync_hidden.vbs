Option Explicit

Dim shell, fso, root, logDir, command, exitCode

Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

root = "C:\xampp\htdocs\site\projetocrm"
logDir = root & "\storage\logs"

If Not fso.FolderExists(logDir) Then
    fso.CreateFolder(logDir)
End If

shell.CurrentDirectory = root
command = "cmd.exe /d /c """"C:\xampp\php\php.exe"" ""C:\xampp\htdocs\site\projetocrm\scripts\google_calendar_sync.php"" >> ""C:\xampp\htdocs\site\projetocrm\storage\logs\google_calendar_sync.log"" 2>&1"""
exitCode = shell.Run(command, 0, True)

WScript.Quit exitCode
