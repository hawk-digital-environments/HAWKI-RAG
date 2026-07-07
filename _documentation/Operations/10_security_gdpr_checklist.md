---
sidebar_position: 10
---

# Security and GDPR Checklist

This is an engineering checklist for HAWKI-RAG. It is not legal advice. For a real deployment in Germany or the EU, review this with the organisation's Datenschutzbeauftragte/r or legal counsel.

## Sources Checked

- Official EU business guidance: https://commission.europa.eu/law/law-topic/data-protection/rules-business-and-organisations_en
- Official GDPR regulation text: https://eur-lex.europa.eu/eli/reg/2016/679/oj/eng
- GitHub GDPR skill/checklist found during research:
  - https://github.com/SM4RTENHEIMER/gdpr-compliance
  - https://github.com/privacyradius/gdpr-checklist
  - https://github.com/mirkoschubert/datenschutz-checkliste

Use GitHub checklists as developer inspiration. Treat official EU/German guidance and legal review as the source of truth.

## Must Do Before Production

1. Define the processing purpose.
   HAWKI-RAG must clearly state why documents, chat prompts, citations, graph nodes, and analytics are processed.

2. Document the lawful basis.
   For every data pool, record whether processing is based on consent, contract, legitimate interest, legal obligation, or another GDPR basis.

3. Keep a record of processing activities.
   Track data categories, user groups, recipients, processors, storage locations, retention periods, and deletion procedures.

4. Apply data minimisation.
   Do not ingest private documents, student data, user chats, or personal metadata unless the RAG use case needs it.

5. Define retention and deletion.
   A delete action must cover PostgreSQL metadata, uploaded files, generated Markdown, Qdrant points, Neo4j nodes/relationships, RAG-Anything KV/cache, graph snapshots, logs, and backups.

6. Explain user rights.
   Support access, export, correction, deletion, objection, and portability workflows for personal data in documents and chats.

7. Protect children and student data.
   For schools and universities, treat student records, grades, teacher comments, chat history, and uploaded lesson material as sensitive operational data.

8. Keep privacy notices understandable.
   Users must know what HAWKI-RAG stores, why it stores it, how long it keeps it, who can access it, and how deletion works.

9. Run a DPIA when needed.
   Do a Data Protection Impact Assessment when the system handles high-volume student data, sensitive topics, profiling, monitoring, or automated decision support.

10. Prepare breach response.
    Keep a process for detecting, documenting, escalating, and reporting personal data breaches.

## HAWKI-RAG Data Map

| Area | Personal-data risk | Required control |
| --- | --- | --- |
| Uploaded files | PDFs, images, lesson documents, names, emails, student data | Validate file type/size, restrict access, define retention |
| Generated Markdown | Extracted text can contain the same personal data as originals | Delete with source document, avoid public paths |
| PostgreSQL | Users, datasets, documents, pipeline tasks, job metadata | Use least privilege, migrations, backups, retention |
| Qdrant | Vector points can represent personal text and metadata | Delete by document/dataset, restrict collection cleanup |
| Neo4j | Entities and relationships can expose personal connections | Delete related graph nodes/relationships, secure clear operations |
| RAG-Anything KV/cache | Full docs, entities, relationships, processing cache | Clear cache on document/dataset deletion |
| Graph snapshots | Saved graph views can preserve personal nodes | Treat as derived personal data |
| Logs | Prompts, URLs, filenames, errors, trace IDs | No secrets or full personal text in logs |
| Chat prompts | User questions can contain personal data | Limit retention, support deletion/export |
| Backups | Deleted data can remain recoverable | Document backup retention and restore deletion handling |

## Security Controls

1. Browser hardening.
   Keep CSP, frame, MIME, referrer, permissions, and cross-origin headers enabled.

2. Internal API hardening.
   Keep internal APIs behind Sanctum bearer tokens and rate limits. Do not expose `/api/*` without authentication.

3. Destructive action hardening.
   Keep delete, retry, cancel, Qdrant cleanup, and Neo4j clear routes separately throttled.

4. Input validation.
   Every route must validate IDs, URLs, collection names, upload names, limits, and booleans before work starts.

5. Filesystem boundaries.
   Local pipeline files and conversion output directories must stay inside configured HAWKI-RAG storage roots.

6. Token lifecycle.
   Use prefixed Sanctum tokens, expiration, revocation, and per-environment secrets.

7. Production secrets.
   Replace `change_me`, disable debug, use HTTPS, rotate leaked credentials, and do not commit `.env`.

8. Service isolation.
   Qdrant, Neo4j, PostgreSQL, Temporal, Ollama, and the RAG bridge should stay on private Docker networks unless there is a deliberate public gateway.

9. Audit and observability.
   Log security-relevant actions without storing secrets, full prompts, full document text, or personal data dumps.

10. Backups and restore drills.
    Backups must be encrypted, access-controlled, retention-bound, and tested.

## Deletion Boundary

When a user deletes a dataset or document, deletion is only complete after these stores are handled:

1. PostgreSQL rows for datasets, documents, pipeline jobs, tasks, and ingestion sources.
2. Files under shared storage and generated document outputs.
3. Qdrant points for the affected document or collection.
4. Neo4j graph nodes/relationships derived from the document.
5. RAG-Anything KV/cache files for full docs, entities, and relationships.
6. Graph snapshots that include affected nodes.
7. Logs or reports that contain personal data.
8. Backups according to the documented backup retention policy.

## Next Hardening Backlog

1. Replace current inline Blade styles/scripts so CSP can move from `unsafe-inline` to nonce-based scripts and styles.
2. Add role/ability checks for read, upload, retry, delete, Qdrant cleanup, and Neo4j clear actions.
3. Add a deletion audit report that confirms PostgreSQL, Qdrant, Neo4j, KV/cache, and storage cleanup.
4. Add privacy-safe log redaction for prompts, filenames, URLs, and extracted text.
5. Add a GDPR export/delete command for datasets and user chats.
6. Add backup retention docs and restore/delete handling.
