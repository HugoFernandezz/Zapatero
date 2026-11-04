"""Genera los PDF desde los Markdown revisables: python scripts/generar-documentos.py.
Requiere reportlab. No realiza llamadas de red ni utiliza la configuración privada.
"""
from pathlib import Path
import re
from xml.sax.saxutils import escape

from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    SimpleDocTemplate, Paragraph, Spacer, PageBreak, Table, TableStyle, Preformatted,
)
from reportlab.graphics.shapes import Drawing, Rect, String, Line, Polygon

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'output' / 'pdf'
OUT.mkdir(parents=True, exist_ok=True)
INK = colors.HexColor('#17252b')
TEAL = colors.HexColor('#006d77')
PALE = colors.HexColor('#eef5f3')
MUTED = colors.HexColor('#526068')

font_dir = Path('C:/Windows/Fonts')
FONT, BOLD = 'Helvetica', 'Helvetica-Bold'
if (font_dir / 'arial.ttf').exists():
    pdfmetrics.registerFont(TTFont('Body', str(font_dir / 'arial.ttf')))
    pdfmetrics.registerFont(TTFont('BodyBold', str(font_dir / 'arialbd.ttf')))
    pdfmetrics.registerFontFamily('Body', normal='Body', bold='BodyBold', italic='Body', boldItalic='BodyBold')
    FONT, BOLD = 'Body', 'BodyBold'

styles = getSampleStyleSheet()
styles.add(ParagraphStyle('BodyZ', fontName=FONT, fontSize=10, leading=14.1, textColor=INK,
    spaceAfter=8, splitLongWords=True, alignment=TA_LEFT))
styles.add(ParagraphStyle('TitleZ', parent=styles['BodyZ'], fontName=BOLD, fontSize=24,
    leading=29, textColor=TEAL, spaceBefore=7, spaceAfter=17, keepWithNext=True))
styles.add(ParagraphStyle('SubZ', parent=styles['BodyZ'], fontName=BOLD, fontSize=14.5,
    leading=19, spaceBefore=8, spaceAfter=12, keepWithNext=True))
styles.add(ParagraphStyle('HeadingZ', parent=styles['BodyZ'], fontName=BOLD, fontSize=11.4,
    leading=15, spaceBefore=9, spaceAfter=8, keepWithNext=True))
styles.add(ParagraphStyle('CellZ', parent=styles['BodyZ'], fontSize=8.8, leading=12, spaceAfter=0))
styles.add(ParagraphStyle('CodeZ', fontName='Courier', fontSize=8.1, leading=10.7,
    backColor=PALE, borderPadding=8, spaceBefore=3, spaceAfter=13))

def formatted(text):
    text = escape(text)
    text = re.sub(r'\*\*(.+?)\*\*', r'<b>\1</b>', text)
    text = re.sub(r'`([^`]+)`', r'<font name="Courier" size="9">\1</font>', text)
    text = re.sub(r'\[([^\]]+)\]\((https?://[^\s)]+)\)', r'<link href="\2" color="#006d77">\1</link>', text)
    return text

def diagram():
    d = Drawing(480, 177)
    def box(x, y, w, title, sub):
        d.add(Rect(x, y, w, 52, rx=6, ry=6, fillColor=PALE, strokeColor=TEAL, strokeWidth=0.7))
        d.add(String(x+w/2, y+32, title, fontName=BOLD, fontSize=10, textAnchor='middle', fillColor=INK))
        d.add(String(x+w/2, y+15, sub, fontName=FONT, fontSize=8, textAnchor='middle', fillColor=MUTED))
    def arrow(x1,y1,x2,y2):
        d.add(Line(x1,y1,x2,y2,strokeColor=TEAL,strokeWidth=1.2))
        if x2 > x1:
            d.add(Polygon([x2,y2,x2-6,y2+3,x2-6,y2-3],fillColor=TEAL,strokeColor=TEAL))
        else:
            d.add(Polygon([x2,y2,x2-3,y2+6,x2+3,y2+6],fillColor=TEAL,strokeColor=TEAL))
    box(0,112,132,'Tienda PHP','SQLite de tienda + eventos')
    box(174,112,132,'n8n','WF1 / WF2 / WF3')
    box(348,112,132,'Back-office','SQLite propia + API + logs')
    arrow(132,138,174,138)
    arrow(306,138,348,138)
    box(175,13,130,'Modelo IA','Consulta y clasificación')
    box(348,13,132,'Revisión humana','Aprobación antes del envío')
    arrow(240,112,240,65)
    arrow(414,112,414,65)
    d.add(String(0,26,'Telegram: vinculación con token',fontName=FONT,fontSize=8.1,fillColor=MUTED))
    d.add(String(0,12,'y aviso tras aprobación en WF3.',fontName=FONT,fontSize=8.1,fillColor=MUTED))
    return d

def table(lines):
    rows = [[c.strip() for c in line.strip().strip('|').split('|')] for line in lines]
    rows = [row for row in rows if not all(re.fullmatch(r':?-+:?', c) for c in row)]
    count = len(rows[0])
    widths = {2:[158,322],3:[123,169,188]}.get(count, [480/count]*count)
    data = [[Paragraph(formatted(cell), styles['CellZ']) for cell in row] for row in rows]
    t = Table(data, colWidths=widths, repeatRows=1, hAlign='LEFT')
    t.setStyle(TableStyle([
        ('BACKGROUND',(0,0),(-1,0),PALE), ('VALIGN',(0,0),(-1,-1),'TOP'),
        ('LINEBELOW',(0,0),(-1,0),0.8,TEAL),
        ('LINEBELOW',(0,1),(-1,-1),0.4,colors.HexColor('#dce4e1')),
        ('LEFTPADDING',(0,0),(-1,-1),8), ('RIGHTPADDING',(0,0),(-1,-1),8),
        ('TOPPADDING',(0,0),(-1,-1),7), ('BOTTOMPADDING',(0,0),(-1,-1),7),
    ]))
    return [t, Spacer(1,11)]

def parse(md):
    lines = md.splitlines()
    story, i = [], 0
    while i < len(lines):
        line = lines[i].strip()
        if not line:
            i += 1
            continue
        if line == '<!-- pagebreak -->':
            story.append(PageBreak())
            i += 1
        elif line == '[[DIAGRAMA]]':
            story.extend([diagram(), Spacer(1,10)])
            i += 1
        elif line.startswith('```'):
            i += 1
            code = []
            while i < len(lines) and not lines[i].startswith('```'):
                code.append(lines[i])
                i += 1
            story.append(Preformatted('\n'.join(code), styles['CodeZ']))
            i += 1
        elif line.startswith('|'):
            block = []
            while i < len(lines) and lines[i].strip().startswith('|'):
                block.append(lines[i])
                i += 1
            story.extend(table(block))
        elif line.startswith('#'):
            level = len(line)-len(line.lstrip('#'))
            style = 'TitleZ' if level == 1 else ('SubZ' if level == 2 else 'HeadingZ')
            story.append(Paragraph(formatted(line.lstrip('#').strip()), styles[style]))
            i += 1
        elif line.startswith('- '):
            story.append(Paragraph(formatted(line[2:]), styles['BodyZ'], bulletText='-'))
            i += 1
        else:
            block = [line]
            i += 1
            while i < len(lines) and lines[i].strip() and not lines[i].startswith(('#','|','```','<!--','- ')):
                block.append(lines[i].strip())
                i += 1
            story.append(Paragraph(formatted(' '.join(block)), styles['BodyZ']))
    return story

def page_decoration(canvas, doc):
    w,h = A4
    canvas.saveState()
    canvas.setStrokeColor(TEAL)
    canvas.setLineWidth(1)
    canvas.line(20*mm,h-15*mm,w-20*mm,h-15*mm)
    canvas.setFont(BOLD,8)
    canvas.setFillColor(TEAL)
    canvas.drawString(20*mm,h-12*mm,'ZAPATERO  /  SOLUCIONES INFORMÁTICAS PARA LA EMPRESA')
    canvas.setFont(FONT,7)
    canvas.setFillColor(MUTED)
    canvas.drawString(20*mm,13*mm,'REGISTRO TÉCNICO  |  Estado verificado: 04/11/2026')
    canvas.drawRightString(w-20*mm,13*mm,str(doc.page))
    canvas.restoreState()

for source, name in [('memoria.md','memoria-zapatero.pdf'),('anexo-ia.md','anexo-ia-zapatero.pdf')]:
    doc = SimpleDocTemplate(str(OUT/name), pagesize=A4, leftMargin=20*mm, rightMargin=20*mm,
        topMargin=22*mm, bottomMargin=22*mm, title='Zapatero - ' + source.removesuffix('.md'),
        author='Natalia Castaño Lorente, Irene Almagro Andújar y Nikita Biriouk (asistido por IA)')
    doc.build(parse((ROOT/'docs'/source).read_text(encoding='utf-8')), onFirstPage=page_decoration,onLaterPages=page_decoration)
    print(OUT/name)
