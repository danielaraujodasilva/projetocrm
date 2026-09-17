<?php
/**
 * Liga uma conversa do ARQUIVO da ponte ao LEAD do CRM.
 *
 * POR QUE EXISTE
 * A pagina de Historico le o arquivo da ponte (que tem telefone e origem detectada).
 * A origem "oficial" (a que aparece no ROI e nas listas) mora em `leads.source`, no
 * banco do CRM. Para editar a origem a partir do Historico, preciso achar o lead
 * correspondente pelo telefone.
 *
 * O pareamento usa as MESMAS tolerancias do resto do CRM (com/sem DDI, com/sem o 9),
 * porque o telefone do arquivo pode vir em formatos diferentes.
 *
 * SOMENTE LEITURA do arquivo; escrita apenas em leads.source (pelo chamador).
 */
declare(strict_types=1);

/**
 * Variacoes de um telefone para casar com o banco.
 * Ex.: 5511999998888 -> [5511999998888, 11999998888, 1199999888, 999998888]
 */
function historico_telefone_variacoes(string $phone): array
{
    $d = preg_replace('/\D+/', '', $phone);
    if ($d === '') {
        return [];
    }

    $out = [$d];
    if (str_starts_with($d, '55') && strlen($d) > 11) {
        $semPais = substr($d, 2);
        $out[] = $semPais;
        // 11 digitos (com o 9) <-> 10 digitos (sem o 9)
        if (strlen($semPais) === 11) {
            $out[] = substr($semPais, 0, 2) . substr($semPais, 3);
        } elseif (strlen($semPais) === 10) {
            $out[] = substr($semPais, 0, 2) . '9' . substr($semPais, 2);
        }
    } elseif (strlen($d) === 11) {
        $out[] = substr($d, 0, 2) . substr($d, 3);
        $out[] = '55' . $d;
    } elseif (strlen($d) === 10) {
        $out[] = substr($d, 0, 2) . '9' . substr($d, 2);
        $out[] = '55' . $d;
    }

    return array_values(array_unique(array_filter($out)));
}

/**
 * Acha o lead do CRM pelo telefone da conversa.
 * Retorna ['id'=>, 'name'=>, 'source'=>, 'phone'=>] ou null.
 */
function historico_lead_por_telefone(PDO $pdo, string $phone): ?array
{
    $variacoes = historico_telefone_variacoes($phone);
    if (!$variacoes) {
        return null;
    }

    // Compara ignorando nao-digitos: o banco guarda em formatos variados.
    $placeholders = implode(' OR ', array_fill(0, count($variacoes), "REPLACE(REPLACE(REPLACE(phone,'(',''),')',''),'-','') = ?"));
    try {
        $stmt = $pdo->prepare("SELECT id, name, source, phone FROM leads WHERE phone IS NOT NULL AND ($placeholders) ORDER BY updated_at DESC, id DESC LIMIT 1");
        $stmt->execute($variacoes);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Mapa telefone(variacao) => lead, para preencher a lista inteira de uma vez
 * sem N consultas. Recebe a lista de telefones do arquivo.
 */
function historico_mapa_leads(PDO $pdo, array $telefones): array
{
    $mapa = [];
    $todos = [];
    foreach ($telefones as $t) {
        foreach (historico_telefone_variacoes((string)$t) as $v) {
            $todos[] = $v;
            $mapa[$v] = (string)$t; // aponta de volta para o telefone original
        }
    }
    $todos = array_values(array_unique($todos));
    if (!$todos) {
        return [];
    }

    // Em blocos, para nao estourar o limite de parametros.
    $resultado = [];
    foreach (array_chunk($todos, 400) as $bloco) {
        $ph = implode(',', array_fill(0, count($bloco), '?'));
        try {
            $stmt = $pdo->prepare("SELECT id, name, source, phone FROM leads WHERE phone IN ($ph)");
            $stmt->execute($bloco);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $lead) {
                $orig = preg_replace('/\D+/', '', (string)($lead['phone'] ?? ''));
                foreach (historico_telefone_variacoes($orig) as $v) {
                    if (isset($mapa[$v])) {
                        $resultado[$mapa[$v]] = [
                            'id' => (int)$lead['id'],
                            'name' => (string)($lead['name'] ?? ''),
                            'source' => (string)($lead['source'] ?? ''),
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    return $resultado;
}
