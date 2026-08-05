#!/usr/bin/env python3
# ==========================================================
# ocr_receipt.py — SugarCast receipt/ledger photo bridge
#
# Reads an image path from argv[1], runs Tesseract OCR on it,
# and extracts best-guess quantity/price/date candidates with
# a handful of regex heuristics. This is assistive, not
# authoritative: the raw text is always returned alongside the
# guesses so the trader can review and correct them in the UI
# before anything is saved.
#
# Called from backend/sales.php (action=ocr_extract) via
# proc_open, mirroring predict_demand.py's bridge pattern.
# Never raises — all failure modes print
# {"success": false, "message": "..."} so PHP can report a
# clean error instead of a 500.
# ==========================================================

import sys
import json
import re


def fail(message, **extra):
    out = {"success": False, "message": message}
    out.update(extra)
    print(json.dumps(out))
    sys.exit(0)


def main():
    if len(sys.argv) < 2:
        fail("No image path provided.")
        return

    image_path = sys.argv[1]

    try:
        from PIL import Image, ImageOps
    except Exception as e:
        fail(f"Pillow is not installed: {e}")
        return

    try:
        import pytesseract
    except Exception as e:
        fail(f"pytesseract is not installed: {e}")
        return

    try:
        image = Image.open(image_path)
        # Light preprocessing: greyscale + autocontrast measurably helps
        # Tesseract on photographed (not scanned) receipts and handwritten
        # ledger pages, without needing a heavier CV pipeline.
        image = ImageOps.exif_transpose(image)
        image = image.convert("L")
        image = ImageOps.autocontrast(image)
    except Exception as e:
        fail(f"Could not open this image: {e}")
        return

    try:
        raw_text = pytesseract.image_to_string(image)
    except pytesseract.TesseractNotFoundError:
        fail("Tesseract OCR is not installed on this server. See ml/README.md for setup instructions.")
        return
    except Exception as e:
        fail(f"OCR failed: {e}")
        return

    if not raw_text or not raw_text.strip():
        fail("No readable text found in this image. Try a clearer, well-lit photo.")
        return

    extracted = extract_fields(raw_text)

    print(json.dumps({
        "success": True,
        "raw_text": raw_text.strip(),
        "extracted": extracted,
    }))


def extract_fields(text: str) -> dict:
    """Best-guess quantity/price/date from free-form OCR text.

    Deliberately conservative: returns None for anything it isn't
    reasonably confident about rather than guessing wildly, since a wrong
    silent number is worse than an empty field the trader fills in
    themselves.
    """
    lower = text.lower()

    # Date: YYYY-MM-DD, DD/MM/YYYY, or DD-MM-YYYY
    date = None
    m = re.search(r"(20\d{2})[-/](\d{1,2})[-/](\d{1,2})", text)
    if m:
        y, mo, d = m.groups()
        date = f"{y}-{int(mo):02d}-{int(d):02d}"
    else:
        m = re.search(r"(\d{1,2})[-/](\d{1,2})[-/](20\d{2})", text)
        if m:
            d, mo, y = m.groups()
            date = f"{y}-{int(mo):02d}-{int(d):02d}"

    # Quantity: a number near "kg", "quantity", or "qty"
    quantity = None
    m = re.search(r"(\d[\d,]*(?:\.\d+)?)\s*kg", lower)
    if not m:
        m = re.search(r"(?:qty|quantity)\D{0,10}(\d[\d,]*(?:\.\d+)?)", lower)
    if m:
        quantity = float(m.group(1).replace(",", ""))

    # Price per kg: a number near "price", "tsh", "@", or "/kg"
    price = None
    m = re.search(r"(?:price|tsh|@)\D{0,6}(\d[\d,]*(?:\.\d+)?)", lower)
    if not m:
        m = re.search(r"(\d[\d,]*(?:\.\d+)?)\s*/\s*kg", lower)
    if m:
        candidate = float(m.group(1).replace(",", ""))
        # Guard against accidentally matching the quantity itself when the
        # two keywords sit close together in a cramped receipt layout.
        if quantity is None or abs(candidate - quantity) > 0.001:
            price = candidate

    # Fallback: if keyword matching found nothing, take the two most
    # plausible bare numbers on the page (large = quantity, small
    # decimal-like = price) so the trader still has a starting point.
    if quantity is None or price is None:
        numbers = [float(n.replace(",", "")) for n in re.findall(r"\d[\d,]*(?:\.\d+)?", text)]
        numbers = [n for n in numbers if n > 0]
        if quantity is None:
            big = [n for n in numbers if n >= 50]
            if big:
                quantity = max(big)
        if price is None:
            small = [n for n in numbers if 0.1 <= n <= 20000 and n != quantity]
            if small:
                price = min(small)

    return {
        "sale_date": date,
        "quantity_kg": quantity,
        "price_per_kg": price,
    }


if __name__ == "__main__":
    main()
