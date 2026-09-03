#!/usr/bin/env python3
"""Gera o docs/INSTALL.pdf a partir do docs/INSTALL.md (ReportLab).

Uso:  python3 tools/build-install-pdf.py [entrada.md] [saida.pdf]

Não é um conversor de Markdown completo — cobre o que o guia usa: títulos,
parágrafos, listas, tabelas, citações, blocos de código cercados por ``` e as
marcações inline **negrito**, *itálico*, `código` e [texto](url). Se o guia
ganhar um recurso novo de Markdown, ajuste aqui.
"""

import html
import re
import sys

from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import (BaseDocTemplate, Frame, HRFlowable, KeepTogether,
                                ListFlowable, ListItem, PageTemplate, Paragraph,
                                Preformatted, Spacer, Table, TableStyle)

TITLE = "Instalação dos plugins GLPI via git clone"
AUTHOR = "Instant Tecnologia"
HEAD_LEFT = "Instant Tecnologia — Plugins GLPI"
HEAD_RIGHT = "Serviços Gerenciados · Relatórios"

INK = colors.HexColor("#1f2933")
MUTED = colors.HexColor("#6b7280")
RULE = colors.HexColor("#d7dbe0")
CODE_BG = colors.HexColor("#f4f5f7")
QUOTE_BG = colors.HexColor("#fdf6e3")
QUOTE_BAR = colors.HexColor("#d9a441")


def styles():
    ss = getSampleStyleSheet()
    base = ParagraphStyle("body", parent=ss["BodyText"], fontName="Helvetica",
                          fontSize=9.5, leading=13.5, textColor=INK,
                          spaceBefore=0, spaceAfter=6, alignment=TA_LEFT)
    return {
        "body": base,
        "h1": ParagraphStyle("h1", parent=base, fontName="Helvetica-Bold",
                             fontSize=17, leading=21, spaceBefore=0, spaceAfter=10),
        "h2": ParagraphStyle("h2", parent=base, fontName="Helvetica-Bold",
                             fontSize=12.5, leading=16, spaceBefore=12, spaceAfter=6),
        "h3": ParagraphStyle("h3", parent=base, fontName="Helvetica-Bold",
                             fontSize=10.5, leading=14, spaceBefore=9, spaceAfter=4),
        "li": ParagraphStyle("li", parent=base, spaceAfter=3),
        "code": ParagraphStyle("code", parent=base, fontName="Courier",
                               fontSize=8.2, leading=11, textColor=INK,
                               spaceBefore=0, spaceAfter=0),
        "quote": ParagraphStyle("quote", parent=base, fontSize=9, leading=12.5,
                                textColor=colors.HexColor("#5b4a1f"),
                                spaceBefore=0, spaceAfter=0),
        "th": ParagraphStyle("th", parent=base, fontName="Helvetica-Bold",
                             fontSize=9, leading=12, spaceAfter=0),
        "td": ParagraphStyle("td", parent=base, fontSize=9, leading=12, spaceAfter=0),
    }


def inline(text):
    """Marcação inline do Markdown -> mini-HTML do ReportLab.

    Os trechos entre crases saem de cena primeiro (viram marcadores) para que o
    conteúdo deles não sofra as outras regras — mas o texto continua **inteiro**,
    senão um negrito que atravessa um `código` nunca fecharia.
    """
    spans = []

    def stash(m):
        spans.append('<font face="Courier" size="8.6" backColor="#f0f1f3">%s</font>'
                     % html.escape(m.group(1)))
        return "\x00%d\x00" % (len(spans) - 1)

    s = re.sub(r"`([^`]+)`", stash, text)
    s = html.escape(s)
    s = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", r'<link href="\2" color="#0b5cad">\1</link>', s)
    s = re.sub(r"\*\*([^*]+)\*\*", r"<b>\1</b>", s)
    s = re.sub(r"(?<![\w*])\*([^*\n]+)\*(?![\w*])", r"<i>\1</i>", s)
    s = re.sub(r"(?<![\w_])_([^_\n]+)_(?![\w_])", r"<i>\1</i>", s)
    return re.sub(r"\x00(\d+)\x00", lambda m: spans[int(m.group(1))], s)


def dedent(lines):
    pad = min((len(l) - len(l.lstrip()) for l in lines if l.strip()), default=0)
    return [l[pad:] if len(l) >= pad else l for l in lines]


def code_block(lines, st, width):
    body = "\n".join(dedent(lines)) or " "
    inner = Preformatted(body, st["code"])
    t = Table([[inner]], colWidths=[width])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), CODE_BG),
        ("BOX", (0, 0), (-1, -1), 0.5, RULE),
        ("LEFTPADDING", (0, 0), (-1, -1), 7), ("RIGHTPADDING", (0, 0), (-1, -1), 7),
        ("TOPPADDING", (0, 0), (-1, -1), 6), ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
    ]))
    return t


def quote_block(text, st, width):
    t = Table([[Paragraph(inline(text), st["quote"])]], colWidths=[width])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), QUOTE_BG),
        ("LINEBEFORE", (0, 0), (0, -1), 2.2, QUOTE_BAR),
        ("LEFTPADDING", (0, 0), (-1, -1), 9), ("RIGHTPADDING", (0, 0), (-1, -1), 8),
        ("TOPPADDING", (0, 0), (-1, -1), 6), ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
    ]))
    return t


def table_block(rows, st, width):
    cells = [[Paragraph(inline(c), st["th"] if r == 0 else st["td"]) for c in row]
             for r, row in enumerate(rows)]
    ncols = max(len(r) for r in cells)
    # Duas colunas costumam ser "rótulo | valor" (o valor é uma URL longa) — dar a
    # mesma largura aos dois quebra a URL no meio de uma palavra.
    widths = [width * 0.26, width * 0.74] if ncols == 2 else [width / ncols] * ncols
    t = Table(cells, colWidths=widths, repeatRows=1, hAlign="LEFT")
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#eef1f4")),
        ("LINEBELOW", (0, 0), (-1, 0), 0.6, RULE),
        ("INNERGRID", (0, 1), (-1, -1), 0.3, RULE),
        ("BOX", (0, 0), (-1, -1), 0.5, RULE),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 6), ("RIGHTPADDING", (0, 0), (-1, -1), 6),
        ("TOPPADDING", (0, 0), (-1, -1), 4), ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
    ]))
    return t


def parse(md, st, width):
    flow, lines, i = [], md.splitlines(), 0
    while i < len(lines):
        raw = lines[i]
        line = raw.strip()

        if line.startswith("```"):                      # bloco de código
            i += 1
            buf = []
            while i < len(lines) and not lines[i].strip().startswith("```"):
                buf.append(lines[i].rstrip())
                i += 1
            i += 1
            flow += [Spacer(1, 3), code_block(buf, st, width), Spacer(1, 8)]
            continue

        if not line:
            i += 1
            continue

        if re.fullmatch(r"-{3,}|\*{3,}|_{3,}", line):   # régua
            flow += [Spacer(1, 4), HRFlowable(width="100%", thickness=0.6, color=RULE),
                     Spacer(1, 8)]
            i += 1
            continue

        m = re.match(r"^(#{1,3})\s+(.*)", line)         # título
        if m:
            key = {1: "h1", 2: "h2", 3: "h3"}[len(m.group(1))]
            flow.append(Paragraph(inline(m.group(2)), st[key]))
            i += 1
            continue

        if line.startswith(">"):                        # citação
            buf = []
            while i < len(lines) and lines[i].strip().startswith(">"):
                buf.append(lines[i].strip()[1:].strip())
                i += 1
            flow += [Spacer(1, 2), quote_block(" ".join(buf), st, width), Spacer(1, 8)]
            continue

        if line.startswith("|"):                        # tabela
            rows = []
            while i < len(lines) and lines[i].strip().startswith("|"):
                cells = [c.strip() for c in lines[i].strip().strip("|").split("|")]
                if not all(re.fullmatch(r":?-{2,}:?", c) for c in cells):
                    rows.append(cells)
                i += 1
            flow += [Spacer(1, 2), table_block(rows, st, width), Spacer(1, 9)]
            continue

        if re.match(r"^[-*]\s+", line):                 # lista
            items = []
            while i < len(lines):
                cur = lines[i]
                if re.match(r"^\s*[-*]\s+", cur):
                    parts = [re.sub(r"^\s*[-*]\s+", "", cur).rstrip()]
                    i += 1
                    # continuação: linhas indentadas que não abrem outro item
                    while i < len(lines) and lines[i].strip() and \
                            not re.match(r"^\s*[-*]\s+", lines[i]) and \
                            lines[i].startswith(" ") and \
                            not lines[i].strip().startswith("```"):
                        parts.append(lines[i].strip())
                        i += 1
                    items.append(ListItem(Paragraph(inline(" ".join(parts)), st["li"]),
                                          leftIndent=14, value="bulletchar"))
                    # bloco de código pendurado no item
                    if i < len(lines) and lines[i].strip().startswith("```"):
                        break
                elif not lines[i].strip():
                    nxt = lines[i + 1] if i + 1 < len(lines) else ""
                    if re.match(r"^\s*[-*]\s+", nxt):
                        i += 1
                        continue
                    break
                else:
                    break
            if items:
                flow += [ListFlowable(items, bulletType="bullet", bulletFontSize=6,
                                      leftIndent=12, bulletOffsetY=1, spaceAfter=5)]
            continue

        buf = []                                        # parágrafo
        while i < len(lines) and lines[i].strip() and \
                not re.match(r"^\s*([-*]\s|#{1,3}\s|\||>|```)", lines[i]) and \
                not re.fullmatch(r"-{3,}", lines[i].strip()):
            buf.append(lines[i].strip())
            i += 1
        if buf:
            flow.append(Paragraph(inline(" ".join(buf)), st["body"]))
        else:
            i += 1
    return flow


def decorate(canvas, doc):
    canvas.saveState()
    w, h = A4
    canvas.setFont("Helvetica", 7.5)
    canvas.setFillColor(MUTED)
    canvas.drawString(18 * mm, h - 12 * mm, HEAD_LEFT)
    canvas.drawRightString(w - 18 * mm, h - 12 * mm, HEAD_RIGHT)
    canvas.setStrokeColor(RULE)
    canvas.setLineWidth(0.5)
    canvas.line(18 * mm, h - 14 * mm, w - 18 * mm, h - 14 * mm)
    canvas.line(18 * mm, 14 * mm, w - 18 * mm, 14 * mm)
    canvas.drawCentredString(w / 2, 10 * mm, "Página %d" % canvas.getPageNumber())
    canvas.restoreState()


def main():
    src = sys.argv[1] if len(sys.argv) > 1 else "docs/INSTALL.md"
    dst = sys.argv[2] if len(sys.argv) > 2 else "docs/INSTALL.pdf"

    doc = BaseDocTemplate(dst, pagesize=A4,
                          leftMargin=18 * mm, rightMargin=18 * mm,
                          topMargin=18 * mm, bottomMargin=18 * mm,
                          title=TITLE, author=AUTHOR, subject=HEAD_RIGHT)
    frame = Frame(doc.leftMargin, doc.bottomMargin, doc.width, doc.height, id="main")
    doc.addPageTemplates([PageTemplate(id="page", frames=[frame], onPage=decorate)])

    st = styles()
    doc.build(parse(open(src, encoding="utf-8").read(), st, doc.width))
    print("gerado: %s" % dst)


if __name__ == "__main__":
    main()
