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

Ajuste os caminhos conforme a sua instalação (a pasta do GLPI costuma ser
`/var/www/glpi` ou `/var/www/html/glpi`).

**1) Clonar o repositório** (troque a URL pela da sua versão do GLPI):

```bash
cd /tmp
git clone https://github.com/wassoller/instant-glpi11-plugins.git
```

**2) Copiar os dois plugins para a pasta de plugins do GLPI:**

```bash
cp -r /tmp/instant-glpi11-plugins/managedservices /var/www/glpi/plugins/
cp -r /tmp/instant-glpi11-plugins/servicereports  /var/www/glpi/plugins/
```

**3) Ajustar o dono para o usuário do servidor web** (ex.: `www-data`):

```bash
chown -R www-data:www-data /var/www/glpi/plugins/managedservices
chown -R www-data:www-data /var/www/glpi/plugins/servicereports
```

**4) Instalar e ativar** — pela interface **ou** pela linha de comando.

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

**5) Conferir:** os menus **Ativos > Serviços Gerenciados** e
**Gerência > Relatórios** devem aparecer.

---

## 4. A ordem importa

O plugin **Relatórios** (bloco *Gestão financeira*) lê os dados do
**Serviços Gerenciados**. Por isso, **instale o `managedservices` primeiro** e
depois o `servicereports`.

---

## 5. Atualizar para uma versão nova depois

```bash
cd /tmp/instant-glpi11-plugins
git pull
cp -r managedservices /var/www/glpi/plugins/
cp -r servicereports  /var/www/glpi/plugins/
chown -R www-data:www-data /var/www/glpi/plugins/managedservices /var/www/glpi/plugins/servicereports
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

- **"Não aparece o menu."** Confirme que o plugin está *Ativado* em
  Configuração > Plugins e que o seu perfil tem o direito do plugin
  (o Super-Admin recebe acesso total automaticamente na instalação).
- **Versão errada do GLPI.** Cada repositório é específico: use o de GLPI 10
  num GLPI 10 e o de GLPI 11 num GLPI 11. O plugin recusa a instalação fora da
  faixa suportada.
