<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manual da Atendente - WhatsApp Mobile | Projeto CRM</title>
    <meta name="description" content="Manual didático para treinamento de atendentes no WhatsApp Mobile do Projeto CRM.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --ink: #101828;
            --muted: #667085;
            --brand: #1f6f78;
            --brand-2: #38a3a5;
            --soft: #eef7f8;
            --paper: #ffffff;
            --line: rgba(15, 23, 42, .10);
            --shadow: 0 24px 70px rgba(15, 23, 42, .12);
        }
        html { scroll-behavior: smooth; }
        body {
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(56, 163, 165, .18), transparent 34rem),
                linear-gradient(180deg, #f7fbfc 0%, #eef3f6 100%);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .hero {
            position: relative;
            overflow: hidden;
            border-radius: 2rem;
            color: #fff;
            background: linear-gradient(135deg, #0f172a 0%, #1f6f78 58%, #38a3a5 100%);
            box-shadow: var(--shadow);
        }
        .hero:after {
            content: "";
            position: absolute;
            width: 24rem;
            height: 24rem;
            right: -9rem;
            bottom: -11rem;
            border-radius: 999px;
            background: rgba(255,255,255,.14);
        }
        .hero > * { position: relative; z-index: 1; }
        .badge-soft {
            border: 1px solid rgba(255,255,255,.28);
            background: rgba(255,255,255,.12);
            color: #fff;
        }
        .card-manual {
            border: 1px solid var(--line);
            border-radius: 1.35rem;
            background: rgba(255,255,255,.86);
            box-shadow: 0 10px 35px rgba(15, 23, 42, .07);
        }
        .nav-pills .nav-link {
            color: var(--ink);
            border-radius: 999px;
            font-weight: 700;
        }
        .nav-pills .nav-link.active {
            background: var(--brand);
        }
        .section-title {
            letter-spacing: -.04em;
            font-weight: 850;
        }
        .icon-box {
            width: 2.6rem;
            height: 2.6rem;
            display: inline-grid;
            place-items: center;
            border-radius: .95rem;
            color: var(--brand);
            background: var(--soft);
            flex: 0 0 auto;
        }
        .why {
            border-left: 4px solid var(--brand-2);
            background: #f8fcfd;
            border-radius: 1rem;
            padding: 1rem;
        }
        .step-number {
            width: 2rem;
            height: 2rem;
            display: inline-grid;
            place-items: center;
            border-radius: 999px;
            color: #fff;
            background: var(--brand);
            font-weight: 800;
            margin-right: .45rem;
        }
        .kbd {
            font-size: .82rem;
            border: 1px solid var(--line);
            border-bottom-width: 2px;
            background: #fff;
            padding: .15rem .45rem;
            border-radius: .45rem;
            font-weight: 700;
        }
        .table thead th { color: var(--muted); font-size: .82rem; text-transform: uppercase; letter-spacing: .05em; }
        .checklist li { margin-bottom: .72rem; }
        .floating-top {
            position: sticky;
            top: 0;
            z-index: 20;
            backdrop-filter: blur(14px);
            background: rgba(247, 251, 252, .82);
            border-bottom: 1px solid var(--line);
        }
        .print-note { display: none; }
        @media print {
            .floating-top, .no-print { display: none !important; }
            .print-note { display: block; }
            body { background: #fff; }
            .card-manual, .hero { box-shadow: none; }
            a { color: #000; text-decoration: none; }
        }
    </style>
</head>
<body>
    <header class="floating-top no-print py-2">
        <div class="container d-flex align-items-center justify-content-between gap-2">
            <div class="fw-bold"><i class="bi bi-whatsapp text-success me-1"></i> Manual WhatsApp Mobile</div>
            <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="index.php?page=studio_whatsapp_mobile"><i class="bi bi-phone me-1"></i> Abrir sistema</a>
                <button class="btn btn-sm btn-primary" onclick="window.print()"><i class="bi bi-printer me-1"></i> Imprimir</button>
            </div>
        </div>
    </header>

    <main class="container py-4 py-lg-5">
        <section class="hero p-4 p-lg-5 mb-4">
            <span class="badge badge-soft rounded-pill px-3 py-2 mb-3">Treinamento da primeira atendente</span>
            <h1 class="display-5 fw-black section-title mb-3">Manual do WhatsApp Mobile</h1>
            <p class="lead mb-4 col-lg-9">Este guia explica como usar o atendimento mobile do CRM, o que cada opção faz e principalmente por que ela existe. A ideia é simples: responder rápido, não perder cliente, manter o histórico organizado e transformar conversa em venda.</p>
            <div class="row g-3">
                <div class="col-md-4"><div class="p-3 rounded-4 bg-white bg-opacity-10 border border-white border-opacity-25 h-100"><strong>1. Atender</strong><br><span class="opacity-75">Entrar na conversa certa e responder com contexto.</span></div></div>
                <div class="col-md-4"><div class="p-3 rounded-4 bg-white bg-opacity-10 border border-white border-opacity-25 h-100"><strong>2. Organizar</strong><br><span class="opacity-75">Assumir, liberar, transferir e registrar informações.</span></div></div>
                <div class="col-md-4"><div class="p-3 rounded-4 bg-white bg-opacity-10 border border-white border-opacity-25 h-100"><strong>3. Vender</strong><br><span class="opacity-75">Conduzir o cliente até orçamento, sinal e agenda.</span></div></div>
            </div>
        </section>

        <section class="card-manual p-3 p-lg-4 mb-4 no-print">
            <ul class="nav nav-pills gap-2 flex-wrap">
                <li class="nav-item"><a class="nav-link active" href="#visao-geral">Visão geral</a></li>
                <li class="nav-item"><a class="nav-link" href="#tela-inicial">Tela inicial</a></li>
                <li class="nav-item"><a class="nav-link" href="#conversa">Conversa</a></li>
                <li class="nav-item"><a class="nav-link" href="#acoes">Ações</a></li>
                <li class="nav-item"><a class="nav-link" href="#cadastro">Cadastro</a></li>
                <li class="nav-item"><a class="nav-link" href="#rotina">Rotina</a></li>
                <li class="nav-item"><a class="nav-link" href="#scripts">Scripts</a></li>
                <li class="nav-item"><a class="nav-link" href="#problemas">Problemas</a></li>
            </ul>
        </section>

        <p class="print-note text-muted">Manual impresso do WhatsApp Mobile do Projeto CRM.</p>

        <section id="visao-geral" class="card-manual p-4 p-lg-5 mb-4">
            <div class="d-flex gap-3 align-items-start mb-3">
                <span class="icon-box"><i class="bi bi-compass"></i></span>
                <div>
                    <h2 class="section-title h1 mb-2">1. O que é este sistema?</h2>
                    <p class="text-secondary mb-0">É a tela de atendimento por WhatsApp do estúdio. Ela junta as conversas dos clientes, mostra quem está atendendo, permite responder, atualizar cadastro e controlar se a conversa está com atendente humano ou com IA.</p>
                </div>
            </div>
            <div class="why mt-4">
                <strong>Por que isso importa?</strong>
                <p class="mb-0 mt-1">Cliente de tatuagem compra confiança. Se a conversa fica perdida, sem histórico, sem responsável ou sem informação básica, a venda esfria. O CRM existe para deixar o atendimento rápido, rastreável e menos dependente de memória humana, esse HD biológico cheio de abas abertas.</p>
            </div>
        </section>

        <section id="tela-inicial" class="card-manual p-4 p-lg-5 mb-4">
            <h2 class="section-title h1 mb-4">2. Tela inicial: lista de conversas</h2>
            <div class="row g-4">
                <div class="col-lg-6">
                    <h3 class="h5"><span class="step-number">1</span> Lista de clientes</h3>
                    <p class="text-secondary">Cada card ou linha representa uma conversa do WhatsApp. Normalmente aparece nome ou telefone, última mensagem, horário e alguns indicadores.</p>
                    <ul>
                        <li><strong>Nome ou telefone:</strong> identifica o cliente.</li>
                        <li><strong>Última mensagem:</strong> mostra rapidamente o assunto.</li>
                        <li><strong>Horário:</strong> ajuda a priorizar quem está esperando há mais tempo.</li>
                        <li><strong>Indicadores:</strong> podem mostrar se tem responsável, se precisa de humano ou se a IA está ativa.</li>
                    </ul>
                </div>
                <div class="col-lg-6">
                    <h3 class="h5"><span class="step-number">2</span> Busca e filtros</h3>
                    <p class="text-secondary">Use a busca para localizar cliente por nome, telefone ou trecho da conversa. Os filtros servem para separar o que precisa de atenção.</p>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead><tr><th>Filtro</th><th>Para que serve</th></tr></thead>
                            <tbody>
                                <tr><td><strong>Todas</strong></td><td>Ver o volume geral de atendimento.</td></tr>
                                <tr><td><strong>Minhas</strong></td><td>Focar só nas conversas que você assumiu.</td></tr>
                                <tr><td><strong>Sem responsável</strong></td><td>Achar cliente novo ou conversa solta.</td></tr>
                                <tr><td><strong>Precisa humano</strong></td><td>Ver onde a IA ou o fluxo automático pediu intervenção.</td></tr>
                                <tr><td><strong>IA ativa</strong></td><td>Conferir conversas que podem estar sendo respondidas automaticamente.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="why mt-4"><strong>Regra de ouro:</strong> antes de responder, confira se a conversa está livre ou se já tem outro atendente nela. Isso evita duas pessoas respondendo coisas diferentes, que é basicamente transformar atendimento em teatro de improviso ruim.</div>
        </section>

        <section id="conversa" class="card-manual p-4 p-lg-5 mb-4">
            <h2 class="section-title h1 mb-4">3. Dentro da conversa</h2>
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="d-flex gap-3"><span class="icon-box"><i class="bi bi-chat-dots"></i></span><div><h3 class="h5">Histórico de mensagens</h3><p class="text-secondary">Mostra tudo que já foi falado com o cliente. Leia antes de responder, principalmente se o cliente já mandou referência, tamanho, local do corpo ou disponibilidade.</p></div></div>
                </div>
                <div class="col-md-6">
                    <div class="d-flex gap-3"><span class="icon-box"><i class="bi bi-send"></i></span><div><h3 class="h5">Campo de resposta</h3><p class="text-secondary">Digite a resposta e envie pelo próprio sistema. Prefira mensagens claras, curtas e com próxima ação. Exemplo: pedir referência, confirmar tamanho, explicar sinal ou sugerir horários.</p></div></div>
                </div>
                <div class="col-md-6">
                    <div class="d-flex gap-3"><span class="icon-box"><i class="bi bi-paperclip"></i></span><div><h3 class="h5">Mídias e referências</h3><p class="text-secondary">Quando o cliente envia imagem ou referência, use isso para entender estilo, região do corpo e complexidade. Nunca dê preço sério sem referência mínima.</p></div></div>
                </div>
                <div class="col-md-6">
                    <div class="d-flex gap-3"><span class="icon-box"><i class="bi bi-mic"></i></span><div><h3 class="h5">Áudios e transcrições</h3><p class="text-secondary">Se houver transcrição, leia para agilizar. Se parecer confusa, escute o áudio. Transcrição ajuda, mas ainda não virou telepatia, infelizmente.</p></div></div>
                </div>
            </div>
        </section>

        <section id="acoes" class="card-manual p-4 p-lg-5 mb-4">
            <h2 class="section-title h1 mb-4">4. Botões e ações principais</h2>
            <div class="accordion" id="acoesAccordion">
                <div class="accordion-item">
                    <h3 class="accordion-header"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#acaoAssumir">Assumir conversa</button></h3>
                    <div id="acaoAssumir" class="accordion-collapse collapse show" data-bs-parent="#acoesAccordion"><div class="accordion-body"><p><strong>O que faz:</strong> marca a conversa como sua responsabilidade.</p><p><strong>Quando usar:</strong> quando você vai responder e acompanhar aquele cliente.</p><p><strong>Por que existe:</strong> evita que outro atendente entre no meio e mande resposta duplicada ou contraditória.</p></div></div>
                </div>
                <div class="accordion-item">
                    <h3 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#acaoLiberar">Liberar conversa</button></h3>
                    <div id="acaoLiberar" class="accordion-collapse collapse" data-bs-parent="#acoesAccordion"><div class="accordion-body"><p><strong>O que faz:</strong> remove você como responsável.</p><p><strong>Quando usar:</strong> quando terminou sua parte, quando vai sair do turno ou quando outra pessoa deve continuar.</p><p><strong>Por que existe:</strong> conversa presa com atendente ausente vira cliente ignorado. Cliente ignorado vira venda perdida.</p></div></div>
                </div>
                <div class="accordion-item">
                    <h3 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#acaoTransferir">Transferir conversa</button></h3>
                    <div id="acaoTransferir" class="accordion-collapse collapse" data-bs-parent="#acoesAccordion"><div class="accordion-body"><p><strong>O que faz:</strong> passa a conversa para outro atendente ou responsável.</p><p><strong>Quando usar:</strong> quando o assunto precisa de outra pessoa, como orçamento específico, agenda, financeiro ou decisão do tatuador.</p><p><strong>Boa prática:</strong> antes de transferir, deixe uma observação curta: o que o cliente quer, onde parou e qual é o próximo passo.</p></div></div>
                </div>
                <div class="accordion-item">
                    <h3 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#acaoIa">Ativar ou desativar IA</button></h3>
                    <div id="acaoIa" class="accordion-collapse collapse" data-bs-parent="#acoesAccordion"><div class="accordion-body"><p><strong>O que faz:</strong> alterna entre atendimento automático e atendimento humano.</p><p><strong>Quando deixar humano:</strong> negociação de valor, cliente bravo, fechamento de agenda, exceções, dúvidas de saúde, cobertura, alteração de projeto ou qualquer coisa que pareça delicada.</p><p><strong>Quando deixar IA:</strong> triagem inicial, respostas simples, captação de informações básicas e horários fora de atendimento.</p><p><strong>Por que existe:</strong> a IA ajuda no volume, mas a venda acontece melhor quando alguém assume o momento certo.</p></div></div>
                </div>
            </div>
        </section>

        <section id="cadastro" class="card-manual p-4 p-lg-5 mb-4">
            <h2 class="section-title h1 mb-4">5. Cadastro, lead e perfil do cliente</h2>
            <p class="text-secondary">A área de cadastro serve para transformar uma conversa solta em cliente organizado dentro do CRM. Preencha aos poucos, conforme a conversa revelar as informações.</p>
            <div class="row g-4">
                <div class="col-lg-4"><div class="p-3 rounded-4 border h-100 bg-white"><h3 class="h5">Dados básicos</h3><p class="text-secondary mb-0">Nome, telefone, Instagram e email. Serve para identificar o cliente e evitar duplicidade.</p></div></div>
                <div class="col-lg-4"><div class="p-3 rounded-4 border h-100 bg-white"><h3 class="h5">Interesse</h3><p class="text-secondary mb-0">Estilo, tema, tamanho, local do corpo, referência e observações. Serve para orçamento e preparação do tatuador.</p></div></div>
                <div class="col-lg-4"><div class="p-3 rounded-4 border h-100 bg-white"><h3 class="h5">Saúde e consentimentos</h3><p class="text-secondary mb-0">Alergias, medicamentos, diabetes, pele, queloide e cicatrização. Serve para segurança do procedimento.</p></div></div>
            </div>
            <div class="why mt-4"><strong>Importante:</strong> se o cliente ainda não sabe responder algo, registre como pendente. Informação pendente visível é melhor do que informação esquecida na cabeça de alguém.</div>
        </section>

        <section id="rotina" class="card-manual p-4 p-lg-5 mb-4">
            <h2 class="section-title h1 mb-4">6. Rotina recomendada da atendente</h2>
            <ol class="checklist">
                <li><span class="step-number">1</span> Abrir o sistema em <span class="kbd">index.php?page=studio_whatsapp_mobile</span>.</li>
                <li><span class="step-number">2</span> Ver primeiro conversas sem responsável e conversas que precisam de humano.</li>
                <li><span class="step-number">3</span> Assumir a conversa antes de responder.</li>
                <li><span class="step-number">4</span> Ler o histórico antes de mandar mensagem.</li>
                <li><span class="step-number">5</span> Descobrir: ideia da tatuagem, região do corpo, tamanho aproximado, referências e disponibilidade.</li>
                <li><span class="step-number">6</span> Atualizar o cadastro ou lead sempre que descobrir uma informação útil.</li>
                <li><span class="step-number">7</span> Conduzir para o próximo passo: orçamento, sinal, agendamento ou avaliação do tatuador.</li>
                <li><span class="step-number">8</span> Liberar ou transferir a conversa quando não for mais sua responsabilidade.</li>
            </ol>
        </section>

        <section id="scripts" class="card-manual p-4 p-lg-5 mb-4">
            <h2 class="section-title h1 mb-4">7. Scripts rápidos de atendimento</h2>
            <div class="row g-3">
                <div class="col-lg-6"><div class="p-3 rounded-4 border bg-white h-100"><h3 class="h6 text-uppercase text-secondary">Primeiro contato</h3><p class="mb-0">Oi! Me manda a ideia da tattoo, o local do corpo, tamanho aproximado em cm e uma referência parecida com o estilo que você quer? Com isso consigo te orientar melhor.</p></div></div>
                <div class="col-lg-6"><div class="p-3 rounded-4 border bg-white h-100"><h3 class="h6 text-uppercase text-secondary">Cliente sem referência</h3><p class="mb-0">Sem referência fica difícil passar um valor justo. Pode ser uma imagem do Google, Pinterest ou alguma tattoo parecida. Não precisa ser igual, é só para entender o estilo.</p></div></div>
                <div class="col-lg-6"><div class="p-3 rounded-4 border bg-white h-100"><h3 class="h6 text-uppercase text-secondary">Fechamento com sinal</h3><p class="mb-0">Para reservar o horário trabalhamos com sinal. Ele garante sua vaga na agenda e é abatido do valor final da tattoo.</p></div></div>
                <div class="col-lg-6"><div class="p-3 rounded-4 border bg-white h-100"><h3 class="h6 text-uppercase text-secondary">Retomada de cliente parado</h3><p class="mb-0">Oi! Passando para ver se ainda quer seguir com sua tattoo. Se quiser, me confirma a ideia e a disponibilidade que eu te ajudo com o próximo passo.</p></div></div>
            </div>
        </section>

        <section id="problemas" class="card-manual p-4 p-lg-5 mb-4">
            <h2 class="section-title h1 mb-4">8. Problemas comuns e o que fazer</h2>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th>Situação</th><th>O que fazer</th><th>Por quê</th></tr></thead>
                    <tbody>
                        <tr><td>Não consigo enviar mensagem</td><td>Atualize a página, confira internet e veja se a conversa está assumida por você.</td><td>Pode ser falha temporária ou permissão da conversa.</td></tr>
                        <tr><td>Cliente mandou áudio longo</td><td>Veja se tem transcrição. Se não tiver ou estiver estranha, escute antes de responder.</td><td>Evita responder errado.</td></tr>
                        <tr><td>Cliente pediu desconto</td><td>Não brigue por preço. Reforce qualidade, segurança, projeto e formas de pagamento.</td><td>Desconto mal explicado desvaloriza o trabalho.</td></tr>
                        <tr><td>Cliente quer cobrir tattoo antiga</td><td>Peça foto bem iluminada, tamanho, local do corpo e explique que precisa avaliação.</td><td>Cobertura depende do desenho antigo, pele e viabilidade.</td></tr>
                        <tr><td>Cliente falou de saúde, alergia ou remédio</td><td>Registre no cadastro e chame responsável antes de confirmar procedimento.</td><td>Segurança vem antes da venda.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card-manual p-4 p-lg-5 mb-5">
            <h2 class="section-title h1 mb-4">Checklist final para treinamento</h2>
            <div class="row g-3">
                <div class="col-md-6"><label class="p-3 rounded-4 border bg-white d-block h-100"><input type="checkbox" class="form-check-input me-2"> A atendente sabe abrir a tela mobile.</label></div>
                <div class="col-md-6"><label class="p-3 rounded-4 border bg-white d-block h-100"><input type="checkbox" class="form-check-input me-2"> Sabe assumir, liberar e transferir conversa.</label></div>
                <div class="col-md-6"><label class="p-3 rounded-4 border bg-white d-block h-100"><input type="checkbox" class="form-check-input me-2"> Entendeu quando usar humano e quando deixar IA.</label></div>
                <div class="col-md-6"><label class="p-3 rounded-4 border bg-white d-block h-100"><input type="checkbox" class="form-check-input me-2"> Sabe preencher cadastro e interesse do cliente.</label></div>
                <div class="col-md-6"><label class="p-3 rounded-4 border bg-white d-block h-100"><input type="checkbox" class="form-check-input me-2"> Sabe pedir referência, tamanho e região do corpo.</label></div>
                <div class="col-md-6"><label class="p-3 rounded-4 border bg-white d-block h-100"><input type="checkbox" class="form-check-input me-2"> Sabe conduzir para sinal e agendamento.</label></div>
            </div>
        </section>
    </main>

    <footer class="container pb-5 text-center text-secondary small">
        Manual v1 - Projeto CRM - WhatsApp Mobile para atendentes<br>
        Link do sistema: <a href="index.php?page=studio_whatsapp_mobile">abrir WhatsApp Mobile</a>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
