' ==========================================================
' Baixa modelo GRANDE direto do Hugging Face.
'
' POR QUE NAO USAR `lms get`
' O CLI do LM Studio travou em 0 MB ao baixar o Qwen3-30B-A3B (17 GB),
' mesmo com conectividade e espaco OK. Baixando direto do Hugging Face
' com curl (que retoma de onde parou), o download anda a ~9 MB/s.
'
' Progresso em: projetocrm\storage\logs\download_modelo.json
' ==========================================================
Option Explicit

Dim shell, fso, logDir, url, destino, tmp, logFile, command
Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

logDir = "C:\Users\server_spd\Documents\whatsapp-origin-bridge\dados"
If Not fso.FolderExists(logDir) Then
    fso.CreateFolder(logDir)
End If
logFile = logDir & "\download-moe.log"

' Destino no D: (modelos pesados nunca no C:).
destino = "D:\AI\Models\LMStudio\lmstudio-community\Qwen3-30B-A3B-GGUF"
If Not fso.FolderExists(destino) Then
    fso.CreateFolder(destino)
End If

tmp = destino & "\Qwen3-30B-A3B-Q4_K_M.gguf.part"
url = "https://huggingface.co/lmstudio-community/Qwen3-30B-A3B-GGUF/resolve/main/Qwen3-30B-A3B-Q4_K_M.gguf"

Set f = fso.OpenTextFile(logFile, 8, True)
f.WriteLine "[" & Now & "] iniciando download para " & destino
f.Close

' -L segue redirects; -C - retoma de onde parou (se o .part existir).
command = "cmd.exe /d /c ""curl.exe -L -C - --retry 5 --retry-delay 5 --retry-all-errors -o """ & tmp & """ """ & url & """ >> """ & logFile & """ 2>&1"""

shell.CurrentDirectory = destino
shell.Run command, 0, True

' Se terminou, renomeia .part para .gguf.
If fso.FileExists(tmp) Then
    Dim tamanho
    tamanho = fso.GetFile(tmp).Size
    If tamanho > 1000000000 Then
        If fso.FileExists(destino & "\Qwen3-30B-A3B-Q4_K_M.gguf") Then
            fso.DeleteFile destino & "\Qwen3-30B-A3B-Q4_K_M.gguf", True
        End If
        fso.MoveFile tmp, destino & "\Qwen3-30B-A3B-Q4_K_M.gguf"
        Set f = fso.OpenTextFile(logFile, 8, True)
        f.WriteLine "[" & Now & "] CONCLUIDO: " & Round(tamanho / 1073741824, 2) & " GB"
        f.Close
    Else
        Set f = fso.OpenTextFile(logFile, 8, True)
        f.WriteLine "[" & Now & "] INCOMPLETO: " & Round(tamanho / 1048576, 1) & " MB (o job roda de novo e retoma)"
        f.Close
    End If
End If

WScript.Quit 0
