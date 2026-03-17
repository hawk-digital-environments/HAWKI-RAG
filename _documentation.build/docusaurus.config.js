// @ts-check
import {themes as prismThemes} from 'prism-react-renderer';
import {changelogSorter} from './changelogSorter.js';
import remarkRewriteCrossDocLinks from './remarkRewriteCrossDocLinks.js';

// The "x" is there, because the docs are stored in the parent directory, which confuses docusaurus
// It will be automatically be stripped out by docusaurus
const editUrl = 'https://github.com/hawk-digital-environments/HAWKI-RAG/edit/main/x/';
const githubOrganization = 'hawk-digital-environments';
const githubProject = 'HAWKI-RAG';

/** @type {import('@docusaurus/types').Config} */
const config = {
    title: 'HAWKI RAG Docs - Learn how to HAWKI',
    tagline: 'Latest documentation',
    favicon: 'img/favicon-32x32.png',

    url: 'https://rag.hawki.info',
    baseUrl: '/',

    organizationName: githubOrganization, // Update accordingly
    projectName: githubProject, // Update with your actual project name

    onBrokenLinks: 'throw',

    i18n: {
        defaultLocale: 'en',
        locales: ['en']
    },

    markdown: {
        mermaid: true,
        hooks: {
            onBrokenMarkdownLinks: 'warn'
        }
    },

    plugins: [
        require.resolve('docusaurus-lunr-search'),
        [
            '@docusaurus/plugin-content-docs',
            {
                path: '../_changelog',
                routeBasePath: 'changelog',
                id: 'changelog',
                sidebarPath: require.resolve('./sidebars-changelog.js'),
                editUrl: editUrl,
                beforeDefaultRemarkPlugins: [remarkRewriteCrossDocLinks],
                async sidebarItemsGenerator({docs}) {
                    return changelogSorter(docs, githubOrganization, githubProject);
                }
            }
        ]
    ],

    presets: [
        [
            'classic',
            /** @type {import('@docusaurus/preset-classic').Options} */
            ({
                docs: {
                    path: '../_documentation',
                    routeBasePath: '/',
                    sidebarPath: require.resolve('./sidebars-docs.js'),
                    editUrl: editUrl,
                    beforeDefaultRemarkPlugins: [remarkRewriteCrossDocLinks]
                },
                theme: {
                    customCss: require.resolve('./custom.css')
                }
            })
        ]
    ],

    themes: ['@docusaurus/theme-mermaid'],

    themeConfig: /** @type {import('@docusaurus/preset-classic').ThemeConfig} */ ({
        image: '/assets/HAWKI_RAG_Logo.png',
        navbar: {
            title: '',
            logo: {
                alt: 'HAWK Logo',
                src: '/img/hawk-logo.svg',
                srcDark: '/img/hawk-logo-dark.svg'
            },
            items: [
                {
                    href: 'https://docs.hawki.info/',
                    position: 'right',
                    className: 'header-docs-link',
                    title: 'HAWKI Documentation',
                    label: 'HAWKI Docs',
                    'aria-label': 'HAWKI Documentation'
                },
                {
                    href: 'https://hawki-info.hawk.de/',
                    position: 'right',
                    className: 'header-info-link',
                    title: 'Official HAWKI website',
                    'aria-label': 'Official HAWKI website'
                },
                {
                    href: 'https://github.com/hawk-digital-environments/HAWKI-RAG',
                    position: 'right',
                    className: 'header-github-link',
                    title: 'GitHub repository',
                    'aria-label': 'GitHub repository'
                },
                {
                    href: 'https://discord.gg/zzR54sRWDE',
                    position: 'right',
                    className: 'header-discord-link',
                    title: 'Join our Discord server',
                    'aria-label': 'Discord server'
                }
            ]
        },
        footer: {
            copyright: `Made with Docusaurus © ${new Date().getFullYear()}`
        },
        prism: {
            theme: prismThemes.github,
            darkTheme: prismThemes.dracula
        }
    })
};

export default config;
