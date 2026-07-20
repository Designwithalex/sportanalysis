const Api = {
    async get(url) {
        const res = await fetch(url);
        return Api._handle(res);
    },
    async postForm(url, formData) {
        const res = await fetch(url, { method: 'POST', body: formData });
        return Api._handle(res);
    },
    async del(url) {
        const res = await fetch(url, { method: 'DELETE' });
        return Api._handle(res);
    },
    async _handle(res) {
        const data = await res.json();
        if (!res.ok || !data.ok) {
            throw new Error(data.error || 'Ocurrió un error inesperado.');
        }
        return data;
    },
};
