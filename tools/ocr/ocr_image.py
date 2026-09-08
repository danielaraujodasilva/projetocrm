#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""OCR de imagem (rapidocr/onnxruntime, 100% local, sem nuvem).

Uso: python ocr_image.py <caminho_da_imagem>
Saida: linhas de texto reconhecido, em UTF-8 (inclui 'OCR_ERROR:' em stdout se falhar).
"""
import sys, io, os, traceback

def main():
    if len(sys.argv) < 2:
        print("OCR_ERROR: falta caminho da imagem")
        return 1
    path = sys.argv[1]
    if not os.path.isfile(path):
        print("OCR_ERROR: arquivo nao encontrado: " + path)
        return 2
    try:
        # garante stdout em UTF-8 mesmo com console cp1252
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
        from rapidocr_onnxruntime import RapidOCR
        engine = RapidOCR()
        result, _elapse = engine(path)
        if not result:
            print("(sem texto detectado na imagem)")
            return 0
        out = []
        for _box, text, _score in result:
            t = (text or "").strip()
            if t:
                out.append(t)
        print("\n".join(out))
        return 0
    except Exception as exc:  # noqa: BLE001
        print("OCR_ERROR: " + str(exc))
        traceback.print_exc(file=sys.stderr)
        return 3

if __name__ == "__main__":
    sys.exit(main())
