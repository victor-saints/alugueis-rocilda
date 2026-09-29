<?php

date_default_timezone_set('America/Fortaleza');
ini_set('display_errors', '1');   
error_reporting(E_ALL);
session_start();


function lerValor($s) {
    $s = trim(str_replace(['R$', ' '], '', (string)$s));
    if (strpos($s, ',') !== false) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
        $s = str_replace('.', '', $s);
    }
    return round((float)$s, 2);
}
function brl($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }


$pasta = __DIR__ . '/dados';
if (!is_dir($pasta)) { mkdir($pasta, 0775, true); }
file_put_contents($pasta . '/.htaccess', "Require all denied\nDeny from all\n");

$db = new PDO('sqlite:' . $pasta . '/alugueis.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec("CREATE TABLE IF NOT EXISTS inquilinos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL,
    apartamento TEXT NOT NULL,
    dia_vencimento INTEGER NOT NULL,
    coluna TEXT DEFAULT '',
    unidade_consumidora TEXT DEFAULT '',
    valor_aluguel REAL DEFAULT 0,
    criado_em TEXT DEFAULT CURRENT_TIMESTAMP
)");

$existentes = array_column($db->query('PRAGMA table_info(inquilinos)')->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach (['coluna' => "TEXT DEFAULT ''", 'unidade_consumidora' => "TEXT DEFAULT ''", 'valor_aluguel' => 'REAL DEFAULT 0'] as $col => $tipo) {
    if (!in_array($col, $existentes)) { $db->exec("ALTER TABLE inquilinos ADD COLUMN $col $tipo"); }
}

$db->exec("CREATE TABLE IF NOT EXISTS pagamentos (
    inquilino_id INTEGER NOT NULL,
    mes TEXT NOT NULL,
    pago_em TEXT NOT NULL,
    PRIMARY KEY (inquilino_id, mes),
    FOREIGN KEY (inquilino_id) REFERENCES inquilinos(id) ON DELETE CASCADE
)");


if (empty($_SESSION['token'])) { $_SESSION['token'] = bin2hex(random_bytes(16)); }
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$hoje    = new DateTime('today');
$mesAtual = $hoje->format('Y-m');
$mesesPt = ['', 'janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['token'], $_POST['token'] ?? '')) { http_response_code(400); exit('Sessão expirada. Volte e tente de novo.'); }
    $acao = $_POST['acao'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);

    if ($acao === 'salvar') {
        $nome = trim($_POST['nome'] ?? '');
        $ap   = trim($_POST['apartamento'] ?? '');
        $dia  = (int)($_POST['dia_vencimento'] ?? 0);
        $col  = trim($_POST['coluna'] ?? '');
        $uc   = trim($_POST['unidade_consumidora'] ?? '');
        $val  = lerValor($_POST['valor_aluguel'] ?? '0');
        if ($nome !== '' && $ap !== '' && $dia >= 1 && $dia <= 31) {
            if ($id > 0) {
                $db->prepare('UPDATE inquilinos SET nome=?, apartamento=?, dia_vencimento=?, coluna=?, unidade_consumidora=?, valor_aluguel=? WHERE id=?')
                   ->execute([$nome, $ap, $dia, $col, $uc, $val, $id]);
                $_SESSION['msg'] = 'Dados de ' . $nome . ' atualizados.';
            } else {
                $db->prepare('INSERT INTO inquilinos (nome, apartamento, dia_vencimento, coluna, unidade_consumidora, valor_aluguel) VALUES (?,?,?,?,?,?)')
                   ->execute([$nome, $ap, $dia, $col, $uc, $val]);
                $_SESSION['msg'] = $nome . ' foi adicionado.';
            }
        } else {
            $_SESSION['msg'] = 'Preencha nome, apartamento e um dia de vencimento entre 1 e 31.';
        }
    } elseif ($acao === 'pagar') {
        $db->prepare('INSERT OR IGNORE INTO pagamentos (inquilino_id, mes, pago_em) VALUES (?,?,?)')
           ->execute([$id, $mesAtual, date('Y-m-d H:i:s')]);
        $_SESSION['msg'] = 'Pagamento marcado como recebido.';
    } elseif ($acao === 'desfazer') {
        $db->prepare('DELETE FROM pagamentos WHERE inquilino_id=? AND mes=?')->execute([$id, $mesAtual]);
        $_SESSION['msg'] = 'Pagamento desmarcado.';
    } elseif ($acao === 'excluir') {
        $db->prepare('DELETE FROM inquilinos WHERE id=?')->execute([$id]);
        $_SESSION['msg'] = 'Inquilino removido.';
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$msg = $_SESSION['msg'] ?? '';
unset($_SESSION['msg']);


$stmt = $db->prepare("SELECT i.*, p.pago_em FROM inquilinos i
    LEFT JOIN pagamentos p ON p.inquilino_id = i.id AND p.mes = ?
    ORDER BY i.dia_vencimento, CAST(i.apartamento AS INTEGER), i.apartamento");
$stmt->execute([$mesAtual]);
$lista = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ultimoDia = (int)$hoje->format('t');
$totais = ['pago' => 0, 'atrasado' => 0, 'aberto' => 0];
$valorTotal = 0; $valorRecebido = 0;

foreach ($lista as &$l) {
    $valorTotal += (float)$l['valor_aluguel'];
    if ($l['pago_em']) { $valorRecebido += (float)$l['valor_aluguel']; }
    
    $dia = min((int)$l['dia_vencimento'], $ultimoDia);
    $venc = new DateTime($hoje->format('Y-m-') . str_pad($dia, 2, '0', STR_PAD_LEFT));
    $l['venc'] = $venc;
    $dif = (int)$hoje->diff($venc)->format('%r%a');

    if ($l['pago_em']) {
        $l['status'] = 'pago'; $l['texto'] = 'Pago';
        $l['detalhe'] = 'Recebido em ' . date('d/m', strtotime($l['pago_em']));
        $totais['pago']++;
    } elseif ($dif < 0) {
        $l['status'] = 'atrasado'; $l['texto'] = 'Atrasado';
        $l['detalhe'] = abs($dif) . ($dif == -1 ? ' dia' : ' dias') . ' de atraso';
        $totais['atrasado']++;
    } elseif ($dif === 0) {
        $l['status'] = 'hoje'; $l['texto'] = 'Vence hoje'; $l['detalhe'] = 'Vencimento é hoje';
        $totais['aberto']++;
    } else {
        $l['status'] = 'aberto'; $l['texto'] = 'Não pago';
        $l['detalhe'] = $dif == 1 ? 'Vence amanhã' : 'Vence em ' . $dif . ' dias';
        $totais['aberto']++;
    }
}
unset($l);

$filtro = $_GET['ver'] ?? 'todos';
$exibir = array_filter($lista, function ($l) use ($filtro) {
    if ($filtro === 'pagos') return $l['status'] === 'pago';
    if ($filtro === 'pendentes') return $l['status'] !== 'pago';
    return true;
});
$tituloMes = ucfirst($mesesPt[(int)$hoje->format('n')]) . ' de ' . $hoje->format('Y');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1d4f4c">
<title>Aluguéis Rocilda Santana</title>
<style>
  :root {
    --fundo: #f3f5f2;
    --papel: #ffffff;
    --tinta: #1c2624;
    --suave: #5b6866;
    --linha: #dde3df;
    --marca: #1d4f4c;
    --marca-escura: #143735;
    --pago: #1f7a4a;   --pago-bg: #e3f3ea;
    --atraso: #b3261e; --atraso-bg: #fbe7e5;
    --hoje: #9a5b00;   --hoje-bg: #fdf0d5;
    --aberto: #3d4b49; --aberto-bg: #eaeeec;
  }
  * { box-sizing: border-box; }
  html { font-size: 18px; }
  body {
    margin: 0; background: var(--fundo); color: var(--tinta);
    font-family: "Segoe UI", system-ui, -apple-system, Roboto, "Helvetica Neue", Arial, sans-serif;
    line-height: 1.4; -webkit-text-size-adjust: 100%;
  }
  header { background: var(--marca); color: #fff; padding: 1.1rem 1.25rem 1.4rem; }
  .topo { max-width: 1100px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
  h1 { margin: 0; font-size: 1.6rem; font-weight: 700; letter-spacing: .01em; }
  .mes { margin: .15rem 0 0; opacity: .85; font-size: 1rem; }
  main { max-width: 1100px; margin: 0 auto; padding: 1rem 1.25rem 6rem; }

  .btn {
    appearance: none; border: 0; cursor: pointer; font: inherit; font-weight: 600;
    min-height: 52px; padding: .7rem 1.3rem; border-radius: 12px;
    display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
    background: var(--marca); color: #fff; text-decoration: none; touch-action: manipulation;
  }
  .btn:active { transform: scale(.98); }
  .btn:focus-visible, input:focus-visible, a:focus-visible { outline: 3px solid #f2b705; outline-offset: 2px; }
  .btn.claro { background: #fff; color: var(--marca); }
  .btn.contorno { background: transparent; color: var(--marca); box-shadow: inset 0 0 0 2px var(--marca); }
  .btn.pagar { background: var(--pago); }
  .btn.perigo { background: var(--atraso); }
  .btn.grande { min-height: 58px; font-size: 1.05rem; }

  .resumo { display: grid; grid-template-columns: repeat(3, 1fr); gap: .75rem; margin-top: -1.9rem; }
  .resumo a {
    background: var(--papel); border-radius: 14px; padding: .9rem 1rem; text-decoration: none; color: inherit;
    box-shadow: 0 1px 0 var(--linha), 0 6px 16px rgba(20,55,53,.08); border-left: 6px solid var(--linha);
  }
  .resumo b { display: block; font-size: 1.9rem; line-height: 1.1; }
  .resumo span { color: var(--suave); font-size: .95rem; }
  .resumo .r-pago { border-left-color: var(--pago); }
  .resumo .r-atrasado { border-left-color: var(--atraso); }
  .resumo .r-aberto { border-left-color: #9aa8a5; }
  .resumo a.ativo { outline: 3px solid var(--marca); }

  .filtros { display: flex; gap: .5rem; margin: 1.2rem 0 .9rem; flex-wrap: wrap; }
  .filtros a {
    padding: .55rem 1.1rem; border-radius: 999px; text-decoration: none; font-weight: 600;
    background: #e6ebe8; color: var(--marca);
  }
  .filtros a.ativo { background: var(--marca); color: #fff; }

  .aviso { background: #e3f3ea; border: 1px solid #b7dcc7; color: #14532d; padding: .8rem 1rem; border-radius: 12px; margin-top: 1rem; }

  .grade { display: grid; grid-template-columns: 1fr; gap: .9rem; }
  @media (min-width: 700px) { .grade { grid-template-columns: 1fr 1fr; } }
  @media (min-width: 1000px) { .grade { grid-template-columns: repeat(3, 1fr); } }

  .cartao { background: var(--papel); border-radius: 16px; padding: 1.1rem; box-shadow: 0 1px 0 var(--linha), 0 6px 16px rgba(20,55,53,.06); display: flex; flex-direction: column; gap: .8rem; border-top: 6px solid var(--linha); }
  .cartao.pago { border-top-color: var(--pago); }
  .cartao.atrasado { border-top-color: var(--atraso); }
  .cartao.hoje { border-top-color: #e0a100; }
  .cabeca { display: flex; justify-content: space-between; align-items: flex-start; gap: .8rem; }
  .nome { font-size: 1.25rem; font-weight: 700; margin: 0; overflow-wrap: anywhere; }
  .ap { color: var(--suave); margin: .1rem 0 0; }
  .selo { padding: .3rem .8rem; border-radius: 999px; font-weight: 700; font-size: .92rem; white-space: nowrap; }
  .selo.pago { background: var(--pago-bg); color: var(--pago); }
  .selo.atrasado { background: var(--atraso-bg); color: var(--atraso); }
  .selo.hoje { background: var(--hoje-bg); color: var(--hoje); }
  .selo.aberto { background: var(--aberto-bg); color: var(--aberto); }
  .valor { font-size: 1.6rem; font-weight: 700; color: var(--marca); }
  .infos { margin: 0; display: flex; flex-wrap: wrap; gap: .5rem 1.4rem; padding-top: .7rem; border-top: 1px solid var(--linha); }
  .infos dt { color: var(--suave); font-size: .85rem; }
  .infos dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
  .total { margin: 1rem 0 0; color: var(--suave); }
  .total strong { color: var(--pago); font-size: 1.15rem; }
  .data { display: flex; align-items: baseline; gap: .6rem; }
  .data strong { font-size: 1.5rem; }
  .data span { color: var(--suave); }
  .acoes { display: flex; gap: .6rem; margin-top: auto; }
  .acoes form { flex: 1; margin: 0; }
  .acoes .btn { width: 100%; }
  .acoes .icone { flex: 0 0 auto; width: auto; min-width: 52px; }

  .vazio { text-align: center; padding: 2.5rem 1rem; color: var(--suave); background: var(--papel); border-radius: 16px; }

  /* botão flutuante */
  .fab { position: fixed; right: 1.25rem; bottom: 1.25rem; box-shadow: 0 8px 20px rgba(0,0,0,.25); min-height: 60px; padding: .8rem 1.5rem; border-radius: 999px; font-size: 1.05rem; }

  dialog { border: 0; border-radius: 18px; padding: 0; width: min(94vw, 480px); box-shadow: 0 20px 60px rgba(0,0,0,.35); }
  dialog::backdrop { background: rgba(10,25,24,.55); }
  .modal { padding: 1.4rem; }
  .modal h2 { margin: 0 0 1rem; font-size: 1.35rem; }
  label { display: block; font-weight: 600; margin: .9rem 0 .35rem; }
  input[type=text], input[type=number] {
    width: 100%; font: inherit; font-size: 1.1rem; padding: .8rem .9rem; min-height: 54px;
    border: 2px solid #b9c4c0; border-radius: 12px; background: #fff; color: var(--tinta);
  }
  .dois { display: grid; grid-template-columns: 1fr 1fr; gap: .8rem; }
  .dica { color: var(--suave); font-size: .9rem; margin: .3rem 0 0; }
  .rodape-modal { display: flex; gap: .7rem; margin-top: 1.4rem; }
  .rodape-modal .btn { flex: 1; }

  @media (max-width: 520px) {
    html { font-size: 17px; }
    .resumo b { font-size: 1.5rem; }
    .resumo a { padding: .7rem .75rem; }
  }
  @media (prefers-reduced-motion: reduce) { .btn:active { transform: none; } }
</style>
</head>
<body>

<header>
  <div class="topo">
    <div>
      <h1>Aluguéis Rocilda Santana</h1>
      <p class="mes"><?= h($tituloMes) ?> · hoje é dia <?= $hoje->format('j') ?></p>
    </div>
    <button class="btn claro" type="button" onclick="abrirForm()">+ Novo inquilino</button>
  </div>
</header>

<main>
  <?php $qtd = count($lista); ?>
  <div class="resumo">
    <a href="?ver=pagos" class="r-pago <?= $filtro === 'pagos' ? 'ativo' : '' ?>"><b><?= $totais['pago'] ?></b><span>Pagos</span></a>
    <a href="?ver=pendentes" class="r-aberto <?= $filtro === 'pendentes' ? 'ativo' : '' ?>"><b><?= $totais['aberto'] ?></b><span>A receber</span></a>
    <a href="?ver=pendentes" class="r-atrasado"><b><?= $totais['atrasado'] ?></b><span>Atrasados</span></a>
  </div>

  <?php if ($valorTotal > 0): ?>
    <p class="total">Recebido no mês: <strong><?= brl($valorRecebido) ?></strong> de <?= brl($valorTotal) ?></p>
  <?php endif; ?>

  <?php if ($msg): ?><div class="aviso" role="status"><?= h($msg) ?></div><?php endif; ?>

  <nav class="filtros" aria-label="Filtrar lista">
    <a href="?ver=todos" class="<?= $filtro === 'todos' ? 'ativo' : '' ?>">Todos (<?= $qtd ?>)</a>
    <a href="?ver=pendentes" class="<?= $filtro === 'pendentes' ? 'ativo' : '' ?>">Falta pagar (<?= $totais['aberto'] + $totais['atrasado'] ?>)</a>
    <a href="?ver=pagos" class="<?= $filtro === 'pagos' ? 'ativo' : '' ?>">Já pagaram (<?= $totais['pago'] ?>)</a>
  </nav>

  <?php if (!$exibir): ?>
    <div class="vazio">
      <?php if ($qtd === 0): ?>
        <p><strong>Nenhum inquilino cadastrado ainda.</strong></p>
        <button class="btn grande" type="button" onclick="abrirForm()">+ Cadastrar o primeiro</button>
      <?php else: ?>
        <p>Ninguém nesta lista.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
  <section class="grade">
    <?php foreach ($exibir as $l): ?>
      <article class="cartao <?= h($l['status']) ?>">
        <div class="cabeca">
          <div>
            <h2 class="nome"><?= h($l['nome']) ?></h2>
            <p class="ap">Apartamento <?= h($l['apartamento']) ?></p>
          </div>
          <span class="selo <?= h($l['status']) ?>"><?= h($l['texto']) ?></span>
        </div>
        <div class="valor"><?= brl($l['valor_aluguel']) ?></div>
        <div class="data">
          <strong>Dia <?= $l['venc']->format('j') ?></strong>
          <span><?= h($l['detalhe']) ?></span>
        </div>
        <?php if ($l['coluna'] !== '' || $l['unidade_consumidora'] !== ''): ?>
        <dl class="infos">
          <?php if ($l['coluna'] !== ''): ?><div><dt>Coluna</dt><dd><?= h($l['coluna']) ?></dd></div><?php endif; ?>
          <?php if ($l['unidade_consumidora'] !== ''): ?><div><dt>Unidade consumidora</dt><dd><?= h($l['unidade_consumidora']) ?></dd></div><?php endif; ?>
        </dl>
        <?php endif; ?>
        <div class="acoes">
          <form method="post">
            <input type="hidden" name="token" value="<?= h($_SESSION['token']) ?>">
            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <?php if ($l['status'] === 'pago'): ?>
              <button class="btn contorno" name="acao" value="desfazer" onclick="return confirm('Desmarcar o pagamento de <?= h(addslashes($l['nome'])) ?>?')">Desfazer</button>
            <?php else: ?>
              <button class="btn pagar" name="acao" value="pagar">✓ Marcar como pago</button>
            <?php endif; ?>
          </form>
          <button class="btn contorno icone" type="button" aria-label="Editar <?= h($l['nome']) ?>"
            onclick='abrirForm(<?= json_encode(["id"=>(int)$l["id"],"nome"=>$l["nome"],"ap"=>$l["apartamento"],"dia"=>(int)$l["dia_vencimento"],"coluna"=>$l["coluna"],"uc"=>$l["unidade_consumidora"],"valor"=>number_format((float)$l["valor_aluguel"],2,",","")], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>)'>Editar</button>
        </div>
      </article>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
</main>

<button class="btn fab" type="button" onclick="abrirForm()">+ Novo inquilino</button>

<dialog id="form">
  <form method="post" class="modal">
    <h2 id="tituloForm">Novo inquilino</h2>
    <input type="hidden" name="token" value="<?= h($_SESSION['token']) ?>">
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="id" id="f_id" value="0">

    <label for="f_nome">Nome do inquilino</label>
    <input type="text" id="f_nome" name="nome" required autocomplete="off">

    <div class="dois">
      <div>
        <label for="f_ap">Apartamento</label>
        <input type="text" id="f_ap" name="apartamento" required inputmode="numeric" autocomplete="off">
      </div>
      <div>
        <label for="f_dia">Vence no dia</label>
        <input type="number" id="f_dia" name="dia_vencimento" min="1" max="31" required inputmode="numeric">
      </div>
    </div>
    <p class="dica">O aluguel vence neste dia todo mês. Em meses mais curtos, vence no último dia.</p>

    <label for="f_valor">Valor do aluguel (R$)</label>
    <input type="text" id="f_valor" name="valor_aluguel" inputmode="decimal" placeholder="Ex.: 850,00" autocomplete="off">

    <div class="dois">
      <div>
        <label for="f_coluna">Número da coluna</label>
        <input type="text" id="f_coluna" name="coluna" autocomplete="off">
      </div>
      <div>
        <label for="f_uc">Unidade consumidora</label>
        <input type="text" id="f_uc" name="unidade_consumidora" inputmode="numeric" autocomplete="off">
      </div>
    </div>

    <div class="rodape-modal">
      <button class="btn contorno" type="button" onclick="document.getElementById('form').close()">Cancelar</button>
      <button class="btn" type="submit">Salvar</button>
    </div>
  </form>

  <form method="post" id="formExcluir" class="modal" style="display:none; padding-top:0"
        onsubmit="return confirm('Remover este inquilino e o histórico dele? Isso não pode ser desfeito.')">
    <input type="hidden" name="token" value="<?= h($_SESSION['token']) ?>">
    <input type="hidden" name="acao" value="excluir">
    <input type="hidden" name="id" id="ex_id" value="0">
    <button class="btn perigo" type="submit" style="width:100%">Remover inquilino</button>
  </form>
</dialog>

<script>
  const dlg = document.getElementById('form');
  function abrirForm(d) {
    const edit = !!d;
    document.getElementById('tituloForm').textContent = edit ? 'Editar inquilino' : 'Novo inquilino';
    document.getElementById('f_id').value   = edit ? d.id : 0;
    document.getElementById('f_nome').value = edit ? d.nome : '';
    document.getElementById('f_ap').value   = edit ? d.ap : '';
    document.getElementById('f_dia').value  = edit ? d.dia : '';
    document.getElementById('f_valor').value  = edit ? d.valor : '';
    document.getElementById('f_coluna').value = edit ? d.coluna : '';
    document.getElementById('f_uc').value     = edit ? d.uc : '';
    document.getElementById('ex_id').value  = edit ? d.id : 0;
    document.getElementById('formExcluir').style.display = edit ? 'block' : 'none';
    dlg.showModal();
    document.getElementById('f_nome').focus();
  }
  dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });
</script>
</body>
</html>