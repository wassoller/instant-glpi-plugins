# Instalação dos plugins via `git clone`

Guia passo a passo para instalar os plugins **Serviços Gerenciados**
(`managedservices`) e **Relatórios** (`servicereports`) num GLPI, a partir do
repositório privado no GitHub.

---

## 1. Escolha o repositório certo para a sua versão do GLPI

| Versão do GLPI | Repositório |
|---|---|
| **GLPI 10.0.x** | `https://github.com/wassoller/instant-glpi-plugins` |
| **GLPI 11.0.x** | `https://github.com/wassoller/instant-glpi11-plugins` |

> Para descobrir a versão: rodapé do GLPI, ou **Configuração > Geral**, ou na
> raiz do GLPI rode `php bin/console --version`.

---

## 2. Pré-requisitos

- `git` instalado no servidor (ou na máquina de onde vai copiar).
- Acesso aos repositórios **privados** (conta GitHub com permissão + um
  *Personal Access Token* com escopo `repo`, ou chave SSH configurada).
- Acesso ao servidor do GLPI (SSH / sistema de arquivos) e permissão para
  escrever na pasta `plugins/` do GLPI.

---

## 3. Passo a passo (Linux — caminho típico)

Ajuste o caminho do GLPI conforme a sua instalação (costuma ser
`/var/www/glpi`, `/var/www/html/glpi` ou similar).

**1) Defina a variável `REPO` de acordo com a versão do seu GLPI.** Isso garante
que o clone e a cópia usem sempre o **mesmo** nome de pasta (o erro mais comum é
clonar um repositório e copiar do outro):

```bash
REPO=instant-glpi-plugins       # GLPI 10.0.x
# REPO=instant-glpi11-plugins   # GLPI 11.0.x (use esta e comente a de cima)
```

**2) Clonar o repositório:**

```bash
cd /tmp
git clone https://github.com/wassoller/$REPO.git
```

**3) Copiar os dois plugins para a pasta de plugins do GLPI** (ajuste o caminho):

```bash
sudo cp -r /tmp/$REPO/managedservices /var/www/glpi/plugins/
sudo cp -r /tmp/$REPO/servicereports  /var/www/glpi/plugins/
```

**4) Ajustar o dono para o usuário do servidor web** (ex.: `www-data`):

```bash
sudo chown -R www-data:www-data /var/www/glpi/plugins/managedservices
sudo chown -R www-data:www-data /var/www/glpi/plugins/servicereports
```

**5) Instalar e ativar** — pela interface **ou** pela linha de comando.

- **Pela interface:** entre em **Configuração > Plugins**, clique em
  *Instalar* e depois *Ativar* no **Serviços Gerenciados** e, em seguida, no
  **Relatórios**.

- **Pela linha de comando** (na raiz do GLPI):

  ```bash
  php bin/console plugin:install  managedservices
  php bin/console plugin:activate managedservices
  php bin/console plugin:install  servicereports
  php bin/console plugin:activate servicereports
  ```

**6) Conferir:** os menus **Ativos > Serviços Gerenciados** e
**Gerência > Relatórios** devem aparecer.

---

## 4. A ordem importa

O plugin **Relatórios** (bloco *Gestão financeira*) lê os dados do
**Serviços Gerenciados**. Por isso, **instale o `managedservices` primeiro** e
depois o `servicereports`.

---

## 5. Atualizar para uma versão nova depois

> **Caminho do GLPI da Instant (VM `vm-glpi-02`): `/var/www/instant/glpi`.**
> Não é `/var/www/glpi` nem `/var/www/html/glpi`. Copiar para o caminho errado
> faz o `cp` falhar com `No such file or directory` — e, como o `git pull` roda
> normalmente, dá a impressão de que a correção "não fez efeito".

```bash
GLPI=/var/www/instant/glpi      # ajuste em outras instalações
REPO=instant-glpi-plugins       # ou instant-glpi11-plugins (GLPI 11)
cd /tmp/$REPO
git pull
sudo cp -r managedservices servicereports "$GLPI/plugins/"
sudo chown -R www-data:www-data "$GLPI/plugins/managedservices" "$GLPI/plugins/servicereports"
ls -l "$GLPI/plugins/managedservices/inc/managedservice.class.php"   # confirme a data/hora
```

Se houver mudança de estrutura de banco, rode a atualização do plugin em
**Configuração > Plugins** (ou `php bin/console plugin:install <nome>`).

---

## 6. Autenticação do repositório privado

- **HTTPS:** ao clonar, o git pedirá usuário e senha — use um
  *Personal Access Token* do GitHub (com escopo `repo`) no lugar da senha.
- **SSH:** com uma chave SSH cadastrada no GitHub, use a URL SSH:
  `git@github.com:wassoller/instant-glpi11-plugins.git`

---

## 7. Dúvidas comuns

- **`cp: cannot stat '.../managedservices': No such file or directory`.** Você
  clonou um repositório mas copiou do outro (nomes diferentes:
  `instant-glpi-plugins` vs `instant-glpi11-plugins`). Use a mesma variável
  `REPO` do passo 1 no clone **e** na cópia.
- **"Não aparece o menu."** Confirme que o plugin está *Ativado* em
  Configuração > Plugins e que o seu perfil tem o direito do plugin
  (o Super-Admin recebe acesso total automaticamente na instalação). Os direitos
  ficam em **Administração > Perfis > _(o perfil)_**, nas abas *Serviços
  Gerenciados* e *Relatórios*. Em "Relatórios" há **uma linha por bloco**
  (Central de serviços, Gestão financeira, Analistas): dá para liberar só um.
  Um perfil sem nenhum dos três não vê a entrada "Relatórios" em Gerência.
- **"Atualizei os arquivos e o plugin apareceu desativado."** Esperado quando a
  **versão** do plugin muda (foi o caso do `servicereports` na 0.5.6): o GLPI
  exige o botão *Atualizar* em Configuração > Plugins e só então reativa. É
  nesse passo que a migração dos direitos por bloco roda.
- **Versão errada do GLPI.** Cada repositório é específico: use o de GLPI 10
  num GLPI 10 e o de GLPI 11 num GLPI 11. O plugin recusa a instalação fora da
  faixa suportada.
