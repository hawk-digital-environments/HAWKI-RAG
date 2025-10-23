# GDPR Checklist

1. Governance & Legal Basis
   - [ ] Define and document the purpose (per RAG use case), including the roles/person groups allowed to access it. _DSK_OH_RAG_
   - [ ] Determine the legal basis (Art. 6 GDPR per purpose), including assessing whether RAG serves as a risk-reducing measure where AI use without RAG would not be viable. _DSK_OH_RAG_
   - [ ] Contractually clarify processor/joint controllership arrangements if parts of the system do not run entirely on-premise. _DSK_OH_RAG_
   - [ ] Update the record of processing activities (embeddings and the vector database are separate processing steps). _DSK_OH_RAG_
   - [ ] Review/update the DPIA/DSFA (hallucinations, purpose creep, rights enforcement, attack vectors). _DSK_OH_RAG_

2. Architecture Decisions (prefer on-premise)
   - [x] Deploy and version the core RAG components (retriever, embedding model, vector database, LLM) with documentation. _DSK_OH_RAG_
   - [x] Evaluate/use the on-premise option to avoid transmitting data to external LLM providers; consider small/appropriate LLMs (SLM) since facts primarily come from references. _DSK_OH_RAG_
   - [x] Account for RAG limitations (context lengths, semantic proximity ≠ reasoning over long chains); consider iterative retrieval loops/agents where needed. _DSK_OH_RAG_

3. Data Sources & Preparation (Art. 5: Accuracy & Data Minimization)
   - [ ] Curate sources: only trustworthy, current, complete reference documents; define regular review cycles. _DSK_OH_RAG_
   - [ ] Preprocess: remove headers/footers/page numbers; generate clean running text; choose chunk size/overlap to preserve meaningful sections. _DSK_OH_RAG_
   - [ ] Minimize personal data (store only necessary data in the vector database); anonymize/pseudonymize where possible. _DSK_OH_RAG_
   - [ ] For external sources/web search: verify lawfulness and quality before integration; prioritize internal over external data. _DSK_OH_RAG_

4. Embeddings & Vector Database
   - [x] Ensure the embedding model fits language/domain (e.g., proficiency in German), otherwise incorrect matching may occur. _DSK_OH_RAG_
   - [ ] Implement tenant/functional separation of the vector database (e.g., per faculty/project); apply a role/permission model down to the chunk/basket level. _DSK_OH_RAG_
   - [ ] Implement deletion and correction processes (targeted deletions/updates in references and vector entries plus deadline control). _DSK_OH_RAG_
   - [ ] Address integrity protection and attack surfaces (audit trails, data-poisoning/supply-chain controls, MIA risks). _DSK_OH_RAG_

5. Retrieval & Prompting (Transparency & Accuracy)
   - [x] System prompt/guardrails: “Answer only from referenced sources; when unsure, clearly flag or decline.” _DSK_OH_RAG_
   - [x] Provide source references in the UI (display document/chunk references) to ensure traceability—transparency mainly applies to the augmented query, not the LLM internals. _DSK_OH_RAG_
   - [ ] Define a conflict strategy (when sources disagree: priority, “no answer,” or follow-up question). _DSK_OH_RAG_

6. Purpose Limitation (Do Not Mix!)
   - [ ] Enforce roles per purpose; assign the correct role before querying (e.g., exam administration ≠ research). _DSK_OH_RAG_
   - [ ] Minimize linkage risk: strictly control transferring personal data from the vector database to the LLM; supply context without personal references wherever possible. _DSK_OH_RAG_

7. LLM Selection & Operations
   - [ ] Review/document the LLM’s training legality (RAG does not remedy unlawful training). _DSK_OH_RAG_
   - [ ] Avoid fine-tuning with personal data unless absolutely necessary; rely on RAG for facts, and use fine-tuning at most for style/format. _DSK_OH_RAG_
   - [ ] Test configuration: ensure the LLM adheres to the provided context (not overridden by prior knowledge); keep temperature/top-p conservative. _DSK_OH_RAG_

8. Data Subject Rights (Art. 15–17 GDPR)
   - [x] Ensure access, rectification, and deletion are practically achievable for references and vector entries (maintain addressability). _DSK_OH_RAG_
   - [ ] Communicate the LLM’s limitations transparently (rights within models remain largely unresolved—describe procedures and compensating measures). _DSK_OH_RAG_

9. Security (TOMs) & Operations
   - [ ] Implement access protection (SSO/IdP), least privilege, logging (queries, sources, output mode), and alerting. _DSK_OH_RAG_
   - [x] Add a content-safety layer (prompt input/output filtering) including protection against prompt injection and jailbreaks in the RAG context. _DSK_OH_RAG_
   - [ ] Monitor quality: hallucination rate/“no answer” ratios, correction latency (time until source updates reach the system). _DSK_OH_RAG_

10. Policies & User Communication
   - [ ] Publish usage guidelines (do/don’t, use personal data only when necessary; handling of sensitive data, Art. 9/10) and conduct training. _DSK_OH_RAG_
   - [x] Include UI notices: label responses “Based on internal sources X/Y, as of date”; communicate errors/uncertainty clearly. _DSK_OH_RAG_

Mini Go-Live Gate (final check before production)
- [ ] Technical readiness test: verify access matrix, tenant separation, deletion function, and source display. _DSK_OH_RAG_
- [ ] Data protection readiness: purpose, legal basis, DPIA, information obligations, DPA/JCA, and rights processes in place. _DSK_OH_RAG_
- [ ] Security readiness: audit logging, alerting, backups/restore for references and vectors, anti-poisoning controls. _DSK_OH_RAG_
- [ ] Quality & transparency: system prompt/“sources only” policy active; “no answer” path available; UI shows references. _DSK_OH_RAG_
