# pyHanko validation harness for phppdf's CAdES (ETSI.CAdES.detached) signatures.
#
# Usage: python cades-validate.py <pdf> <root-cert.pem-or-der>
#
# Asserts the first embedded signature is intact + cryptographically valid, that
# its /SubFilter is ETSI.CAdES.detached, and that the CAdES signing-certificate
# attribute (signingCertificateV2, OID 1.2.840.113549.1.9.16.2.47) is present in
# the SignerInfo signed attributes. The signingCertificateV2 binding is enforced
# by pyHanko during validation: a certHash that does not match the signer cert
# makes validation fail.
#
# Final line "OK" + exit 0 on success; anything else is a failure.

import sys
import traceback

from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.sign.validation import validate_pdf_signature
from pyhanko_certvalidator import ValidationContext
from asn1crypto import x509, pem

pdf_path = sys.argv[1]
root_path = sys.argv[2]

data = open(root_path, "rb").read()
der = pem.unarmor(data)[2] if pem.detect(data) else data
root = x509.Certificate.load(der)

OID_SIGNING_CERT_V2 = "1.2.840.113549.1.9.16.2.47"

with open(pdf_path, "rb") as f:
    r = PdfFileReader(f)
    sigs = r.embedded_signatures
    if not sigs:
        print("NO_SIGNATURES")
        sys.exit(3)
    sig = sigs[0]

    subfilter = sig.sig_object.get("/SubFilter")
    print("subfilter=%s" % subfilter)
    if str(subfilter) != "/ETSI.CAdES.detached":
        print("WRONG_SUBFILTER")
        sys.exit(5)

    attr_oids = [a["type"].dotted for a in sig.signer_info["signed_attrs"]]
    has_scv2 = OID_SIGNING_CERT_V2 in attr_oids
    print("has_signing_certificate_v2=%s" % has_scv2)
    if not has_scv2:
        print("NO_SIGNING_CERT_V2")
        sys.exit(8)

    vc = ValidationContext(
        trust_roots=[root],
        allow_fetching=False,
        revocation_mode="soft-fail",
    )
    try:
        status = validate_pdf_signature(sig, signer_validation_context=vc)
    except Exception:  # noqa: BLE001
        traceback.print_exc()
        print("VALIDATE_RAISED")
        sys.exit(6)

    print("intact=%s valid=%s trusted=%s" % (status.intact, status.valid, status.trusted))
    if status.intact and status.valid:
        print("OK")
        sys.exit(0)
    print("NOT_VALID")
    sys.exit(4)
