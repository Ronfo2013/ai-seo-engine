# Fixture delle risposte del modello

**Queste risposte non sono registrate da chiamate reali.** Sono costruite a
mano riproducendo la forma documentata dell'API e i modi di fallire descritti
nel `README.md` del progetto — recinti markdown, a capo dentro le stringhe,
caratteri di controllo — che sono la ragione per cui il parsing è sempre stato
fragile.

Servono a due cose:

1. bloccare come regressione ogni caso che in passato ha rotto il motore;
2. permettere ai test di girare **senza toccare la rete e senza consumare quota**.

Quando in Fase 2 si passa agli structured output, i casi malformati non
diventano obsoleti: diventano la prova che il nuovo percorso non può più
incontrarli.
