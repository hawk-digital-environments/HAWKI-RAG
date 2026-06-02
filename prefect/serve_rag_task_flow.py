from __future__ import annotations

import os

from flows.rag_task_flow import rag_task_flow


def main() -> None:
    rag_task_flow.serve(
        name=os.getenv("PREFECT_DEPLOYMENT_SHORT_NAME", "rag-task-flow"),
        tags=["hawki-rag", "pipeline-task-supervisor"],
        description="Supervises one Laravel-owned HAWKI RAG pipeline task until all worker jobs are terminal.",
    )


if __name__ == "__main__":
    main()
